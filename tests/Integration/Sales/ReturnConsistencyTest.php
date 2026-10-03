<?php

use App\Enums\SalesDocumentStatus;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Services\CreditNoteService;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesFixture;

/*
 * T050 · CE-002: tras cualquier combinación de ventas, devoluciones,
 * anulaciones, notas rechazadas y descartes, el stock y los saldos cuadran
 * con lo emitido menos lo devuelto. Secuencias aleatorias con semilla fija:
 * todo sale de mt_rand (Collection::random y shuffle usan random_int, que no
 * respeta mt_srand, y harían la secuencia distinta en cada ejecución).
 */

/** Elemento al azar de una lista, con mt_rand (determinista con mt_srand). */
function pick(iterable $items): mixed
{
    $items = array_values(collect($items)->all());

    return $items[mt_rand(0, count($items) - 1)];
}

it('stock y saldos cuadran tras una secuencia aleatoria', function (int $seed) {
    mt_srand($seed);
    ['company' => $company, 'admin' => $admin, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);

    $products = [
        Product::factory()->create(['company_id' => $company->id, 'sale_price' => '7.50', 'igv_affectation' => '10']),
        Product::factory()->byWeight()->create(['company_id' => $company->id, 'sale_price' => '4.20', 'igv_affectation' => '10']),
        Product::factory()->create(['company_id' => $company->id, 'sale_price' => '4.50', 'igv_affectation' => '20']),
        Product::factory()->service()->create(['company_id' => $company->id, 'sale_price' => '5.00']),
    ];
    foreach (array_slice($products, 0, 3) as $p) {
        ProductLot::factory()->for($p)->quantity('500')->create();
    }
    $sales = app(SalesDocumentService::class);
    $notes = app(CreditNoteService::class);
    $sunat = fn (bool $ok) => app()->instance(SunatSender::class, new FakeSunatSender($ok ? FakeSunatSender::accepted() : FakeSunatSender::rejected()));

    for ($step = 0; $step < 30; $step++) {
        // Semilla por paso: el código bajo prueba (p. ej. Faker en las factories)
        // también consume mt_rand, y así cada paso no depende de los anteriores.
        mt_srand($seed * 1000 + $step);
        $accepted = SalesDocument::whereIn('document_type', ['03'])->where('status', 'accepted')->orderBy('id')->get();
        $op = $accepted->isEmpty() ? 0 : mt_rand(0, 5);

        try {
            match ($op) {
                // Venta (a veces rechazada por SUNAT y luego descartada).
                0, 1 => (function () use ($sales, $seller, $admin, $products, $sunat) {
                    $ok = mt_rand(0, 4) > 0;
                    $sunat($ok);
                    $pool = $products;
                    $chosen = [];
                    foreach (range(1, mt_rand(1, 3)) as $_) {
                        $chosen[] = array_splice($pool, mt_rand(0, count($pool) - 1), 1)[0];
                    }
                    $lines = collect($chosen)->map(fn ($p) => [
                        'product_id' => $p->id,
                        'quantity' => $p->unit->allowsDecimals() ? number_format(mt_rand(250, 3000) / 1000, 3, '.', '') : (string) mt_rand(1, 5),
                        'discount' => mt_rand(0, 2) === 0 ? '0.'.mt_rand(10, 90) : null,
                    ])->values()->all();
                    [$doc] = $sales->issue(['idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash', 'customer_id' => null, 'lines' => $lines], $seller);
                    if (! $ok) {
                        $sales->discard($doc, 'Rechazado en la prueba', $admin);
                    }
                })(),
                // Devolución parcial de una línea al azar.
                2, 3 => (function () use ($accepted, $notes, $seller, $sunat) {
                    $doc = pick($accepted);
                    $line = pick($doc->lines()->get());
                    $remaining = bcsub($line->quantity, (CreditNoteService::returnedByLine($doc)[$line->id] ?? App\Sunat\ReturnedSoFar::none())->quantity, 3);
                    if (bccomp($remaining, '0', 3) <= 0) {
                        return;
                    }
                    $qty = str_contains($line->unit, 'KGM') ? number_format(mt_rand(1, (int) bcmul($remaining, '1000')) / 1000, 3, '.', '') : (string) mt_rand(1, (int) $remaining);
                    $sunat(mt_rand(0, 5) > 0);
                    $notes->issue($doc, ['idempotency_key' => (string) Str::uuid(), 'reason_code' => '07', 'reason' => 'Parcial', 'lines' => [['line_position' => $line->position, 'quantity' => $qty]]], $seller);
                })(),
                // Devolución total o anulación (con o sin reposición).
                4, 5 => (function () use ($accepted, $notes, $seller, $sunat, $op) {
                    $sunat(mt_rand(0, 5) > 0);
                    $notes->issue(pick($accepted), ['idempotency_key' => (string) Str::uuid(), 'reason_code' => $op === 4 ? '06' : '01',
                        'reason' => 'Resto', 'restock' => (bool) mt_rand(0, 1)], $seller);
                })(),
            };
        } catch (ValidationException) {
            // Comprobante ya anulado o devuelto totalmente: esperado en una secuencia aleatoria.
        }

        // Invariante de stock: inicial − vendido (no descartado) + repuesto (notas no rechazadas con reposición).
        foreach (array_slice($products, 0, 3) as $p) {
            $sold = SalesDocument::with('lines')->where('document_type', '03')->where('status', '!=', 'discarded')->get()
                ->flatMap->lines->where('product_id', $p->id)->reduce(fn ($c, $l) => bcadd($c, $l->quantity, 3), '0');
            $back = SalesDocument::with('lines')->where('document_type', '07')->where('status', '!=', 'rejected')->where('restock', true)->get()
                ->flatMap->lines->where('product_id', $p->id)->reduce(fn ($c, $l) => bcadd($c, $l->quantity, 3), '0');
            expect($p->fresh()->stock())->toBe(bcadd(bcsub('500', $sold, 3), $back, 3), "semilla {$seed}, paso {$step}, producto {$p->id}");
        }

        // Invariante de saldos: lo devuelto nunca supera lo emitido y, si no queda nada, cuadra al céntimo.
        foreach (SalesDocument::with('lines')->where('document_type', '03')->where('status', 'accepted')->get() as $doc) {
            $credited = SalesDocument::where('reference_document_id', $doc->id)->where('status', '!=', SalesDocumentStatus::Rejected)->get()
                ->reduce(fn ($c, $n) => bcadd($c, $n->total, 2), '0');
            $returned = CreditNoteService::returnedByLine($doc);
            $nothingLeft = $doc->lines->every(fn ($l) => bccomp(($returned[$l->id] ?? App\Sunat\ReturnedSoFar::none())->quantity, $l->quantity, 3) === 0);

            expect(bccomp($credited, $doc->total, 2))->toBeLessThanOrEqual(0, "semilla {$seed}, paso {$step}: {$doc->display_number}")
                ->and(! $nothingLeft || $credited === $doc->total)->toBeTrue("semilla {$seed}, paso {$step}: {$doc->display_number} {$credited} ≠ {$doc->total}");
        }
    }

    expect(SalesDocument::where('document_type', '07')->count())->toBeGreaterThan(3);
})->with([11, 22, 33, 44]);

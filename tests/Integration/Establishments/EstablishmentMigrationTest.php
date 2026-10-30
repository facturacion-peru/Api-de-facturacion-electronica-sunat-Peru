<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Decimal;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * T002 · RF-009, CE-003 (spec 017): los datos anteriores a los locales quedan
 * en el principal de su empresa, los vendedores asignados a él, y las cifras
 * no cambian.
 *
 * Los datos se escriben con SQL directo sobre el esquema de **antes** de la
 * migración (los modelos y servicios ya esperan el local), y las cifras de
 * después se piden a la API: así se comparan dos cálculos independientes.
 */

const MIGRATION_017 = 'database/migrations/2026_10_30_100001_add_establishments_to_operations.php';

/** Inserta una fila y devuelve su id. */
function insertRow(string $table, array $row): int
{
    return (int) DB::table($table)->insertGetId($row + ['created_at' => now()]);
}

/**
 * Empresa con datos de las specs 002 a 007 en el esquema anterior: entradas,
 * una venta con ticket, una boleta, una nota con devolución y un ajuste.
 *
 * @return array{company: Company, admin: User, sellers: list<User>, products: list<Product>}
 */
function seedLegacyCompany(): array
{
    $company = Company::factory()->withMainEstablishment()->create();
    $admin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();
    $sellers = [
        User::factory()->forCompany($company)->create(),
        User::factory()->forCompany($company, active: false)->create(),
    ];
    $products = Product::factory()->count(2)->create(['company_id' => $company->id]);
    $receipts = Series::factory()->create(['company_id' => $company->id, 'code' => 'B'.random_int(100, 999)]);
    $notes = Series::factory()->create(['company_id' => $company->id, 'document_type' => '07', 'code' => 'BC'.random_int(10, 99)]);

    $lot = fn (Product $p, string $number, string $initial, string $remaining) => insertRow('product_lots', [
        'company_id' => $company->id, 'product_id' => $p->id, 'lot_number' => $number,
        'received_at' => today()->toDateString(), 'initial_quantity' => $initial,
        'remaining_quantity' => $remaining, 'unit_cost' => '2.5000', 'created_by' => $admin->id, 'updated_at' => now(),
    ]);
    $movement = fn (Product $p, int $lotId, string $type, string $qty, string $lotAfter, string $productAfter, ?string $sourceType = null, ?int $sourceId = null) => insertRow('inventory_movements', [
        'company_id' => $company->id, 'product_id' => $p->id, 'lot_id' => $lotId, 'type' => $type,
        'quantity' => $qty, 'lot_balance_after' => $lotAfter, 'product_balance_after' => $productAfter,
        'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $admin->id,
    ]);
    $document = fn (Series $series, int $number, string $total, string $status, ?int $reference = null) => insertRow('sales_documents', [
        'company_id' => $company->id, 'series_id' => $series->id, 'reference_document_id' => $reference,
        'document_type' => $series->document_type->value, 'series_code' => $series->code, 'number' => $number,
        'environment' => 'beta', 'issued_at' => now(), 'seller_id' => $sellers[0]->id, 'payment_method' => 'cash', 'currency' => 'PEN',
        'issuer_ruc' => $company->ruc, 'issuer_name' => $company->razon_social, 'issuer_address' => 'AV. DEMO 123', 'issuer_ubigeo' => '150101',
        'customer_document_type' => '0', 'customer_document_number' => '-', 'customer_name' => 'CLIENTES VARIOS',
        'op_gravadas' => $total, 'op_exoneradas' => '0.00', 'op_inafectas' => '0.00', 'igv' => '0.00', 'discount_total' => '0.00', 'total' => $total,
        'status' => $status, 'xml' => '<Invoice/>', 'hash' => 'hash', 'idempotency_key' => (string) Str::uuid(), 'updated_at' => now(),
    ]);

    [$a, $b] = $products;
    $lotA1 = $lot($a, 'L-1', '10.000', '6.000');
    $lotA2 = $lot($a, 'L-2', '5.000', '5.000');
    $lotB = $lot($b, 'L-1', '8.000', '7.000');

    $movement($a, $lotA1, 'entry', '10.000', '10.000', '10.000');
    $movement($a, $lotA2, 'entry', '5.000', '5.000', '15.000');
    $movement($b, $lotB, 'entry', '8.000', '8.000', '8.000');

    $ticket = insertRow('tickets', [
        'company_id' => $company->id, 'number' => 1, 'status' => 'issued', 'seller_id' => $sellers[0]->id,
        'payment_method' => 'cash', 'subtotal' => '30.00', 'discount_total' => '0.00', 'total' => '30.00',
        'idempotency_key' => (string) Str::uuid(), 'issued_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ticket_sequences')->insert(['company_id' => $company->id, 'last_number' => 1]);
    $movement($a, $lotA1, 'sale', '-3.000', '7.000', '12.000', Ticket::class, $ticket);

    $receipt = $document($receipts, 1, '20.00', 'accepted');
    $movement($a, $lotA1, 'sale', '-2.000', '5.000', '10.000', SalesDocument::class, $receipt);
    $movement($b, $lotB, 'sale', '-1.000', '7.000', '7.000', SalesDocument::class, $receipt);

    $note = $document($notes, 1, '5.00', 'accepted', $receipt);
    $movement($a, $lotA1, 'return', '1.000', '6.000', '11.000', SalesDocument::class, $note);

    return ['company' => $company, 'admin' => $admin, 'sellers' => $sellers, 'products' => $products->all()];
}

/** Cifras de una empresa leídas con SQL, antes de migrar. */
function legacyFigures(Company $company): array
{
    $stock = DB::table('product_lots')->where('company_id', $company->id)->get()
        ->groupBy('product_id')->map(fn ($lots) => Decimal::sum($lots->pluck('remaining_quantity')));

    return [
        'stock' => $stock->sortKeys()->all(),
        'tickets' => DB::table('tickets')->where('company_id', $company->id)->count(),
        'documents' => DB::table('sales_documents')->where('company_id', $company->id)->count(),
        'movements' => DB::table('inventory_movements')->where('company_id', $company->id)->orderBy('id')
            ->get(['id', 'lot_balance_after', 'product_balance_after'])->map(fn ($m) => (array) $m)->all(),
    ];
}

/** Mismas cifras pedidas a la API como administrador, después de migrar. */
function apiFigures(array $seeded): array
{
    app('auth')->forgetGuards();
    app(TenantContext::class)->clear();
    Sanctum::actingAs($seeded['admin']->fresh());

    $stock = collect(test()->getJson('/api/v1/products?per_page=100')->assertOk()->json('data'))
        ->mapWithKeys(fn ($p) => [$p['id'] => Decimal::fromDb($p['stock'], 3)]);
    $movements = collect($seeded['products'])->flatMap(
        fn (Product $p) => test()->getJson("/api/v1/products/{$p->id}/movements")->assertOk()->json('data')
    )->sortBy('id')->map(fn ($m) => [
        'id' => $m['id'],
        'lot_balance_after' => Decimal::fromDb($m['lot_balance_after'], 3),
        'product_balance_after' => Decimal::fromDb($m['product_balance_after'], 3),
    ])->values()->all();

    return [
        'stock' => $stock->sortKeys()->all(),
        'tickets' => test()->getJson('/api/v1/tickets')->assertOk()->json('meta.total'),
        'documents' => test()->getJson('/api/v1/sales-documents')->assertOk()->json('meta.total'),
        'movements' => $movements,
        'today' => test()->getJson('/api/v1/dashboard')->assertOk()->json('data.today.total'),
    ];
}

it('deja los datos existentes en el principal, asigna a los vendedores y conserva las cifras', function () {
    Artisan::call('migrate:rollback', ['--path' => MIGRATION_017, '--force' => true]);
    expect(Schema::hasColumn('product_lots', 'establishment_id'))->toBeFalse();

    $first = seedLegacyCompany();
    $second = seedLegacyCompany();
    $before = [legacyFigures($first['company']), legacyFigures($second['company'])];

    Artisan::call('migrate', ['--force' => true]);

    foreach ([$first, $second] as $i => $seeded) {
        $company = $seeded['company'];
        $main = Establishment::withoutTenancy()->where('company_id', $company->id)->where('is_main', true)->value('id');

        foreach (['product_lots', 'inventory_movements', 'tickets', 'sales_documents'] as $table) {
            expect(DB::table($table)->where('company_id', $company->id)->pluck('establishment_id')->unique()->all())
                ->toBe([$main], "{$table} de la empresa {$i}");
        }

        expect(DB::table('establishment_user')->where('company_id', $company->id)->orderBy('user_id')->pluck('user_id')->all())
            ->toBe(collect($seeded['sellers'])->pluck('id')->sort()->values()->all())
            ->and(DB::table('establishment_user')->where('company_id', $company->id)->pluck('establishment_id')->unique()->all())->toBe([$main])
            ->and(DB::table('establishments')->where('company_id', $company->id)->value('active'))->toBeTruthy();

        $after = apiFigures($seeded);

        expect($after['stock'])->toBe($before[$i]['stock'])
            ->and($after['tickets'])->toBe($before[$i]['tickets'])
            ->and($after['documents'])->toBe($before[$i]['documents'])
            ->and($after['movements'])->toBe(array_map(fn ($m) => [
                'id' => $m['id'],
                'lot_balance_after' => Decimal::fromDb($m['lot_balance_after'], 3),
                'product_balance_after' => Decimal::fromDb($m['product_balance_after'], 3),
            ], $before[$i]['movements']))
            // Ticket 30 + boleta 20 − nota 5, todo de hoy (A-54).
            ->and($after['today'])->toBe('45.00');
    }
});

it('la migración se revierte y vuelve a aplicar sin perder datos', function () {
    Artisan::call('migrate:rollback', ['--path' => MIGRATION_017, '--force' => true]);
    $seeded = seedLegacyCompany();
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('migrate:rollback', ['--path' => MIGRATION_017, '--force' => true]);

    expect(Schema::hasColumn('tickets', 'establishment_id'))->toBeFalse()
        ->and(Schema::hasTable('establishment_user'))->toBeFalse()
        ->and(DB::table('tickets')->where('company_id', $seeded['company']->id)->count())->toBe(1);

    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasColumn('tickets', 'establishment_id'))->toBeTrue();
});

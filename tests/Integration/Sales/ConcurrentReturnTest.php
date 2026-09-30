<?php

use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Services\CreditNoteService;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T034 · RF-005: devoluciones simultáneas de la misma línea nunca superan lo
 * emitido. Con un servicio (sin stock) solo protege el bloqueo del
 * comprobante original: con un bien, restock() sería una segunda red.
 * Solo PostgreSQL y pcntl.
 */

it('cinco devoluciones simultáneas de una línea de 3 unidades: solo 3 pasan', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->service()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
    app()->instance(SunatSender::class, new FakeSunatSender);
    [$boleta] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '3']],
    ], $seller);

    $dir = sys_get_temp_dir().'/returns-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.5;
    $children = [];

    DB::disconnect();

    foreach (range(1, 5) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            app()->instance(SunatSender::class, new FakeSunatSender);
            time_sleep_until($startAt);

            try {
                app(CreditNoteService::class)->issue(SalesDocument::find($boleta->id), [
                    'idempotency_key' => (string) Str::uuid(), 'reason_code' => '07', 'reason' => 'Devolución concurrente',
                    'lines' => [['line_position' => 1, 'quantity' => '1']],
                ], $seller);
                $result = 'ok';
            } catch (Throwable $e) {
                $result = 'rechazada: '.$e->getMessage();
            }

            file_put_contents("{$dir}/{$i}", $result);
            pcntl_exec('/bin/true');
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();
    $results = array_map(fn ($file) => file_get_contents($file), glob("{$dir}/*"));
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);

    $returned = SalesDocumentLine::withoutTenancy()->whereNotNull('reference_line_id')->get()->reduce(fn ($c, $l) => bcadd($c, $l->quantity, 3), '0');

    expect(count(array_filter($results, fn ($r) => $r === 'ok')))->toBe(3, implode(' | ', $results))
        ->and($returned)->toBe('3.000');
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

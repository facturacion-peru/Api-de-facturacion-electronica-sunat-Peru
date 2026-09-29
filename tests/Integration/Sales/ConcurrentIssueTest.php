<?php

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T043 · Dos o más vendedores emiten en la misma serie a la vez: números
 * consecutivos, sin repetir, y el stock descontado una vez por comprobante.
 * Solo PostgreSQL y pcntl.
 */

it('las emisiones simultáneas en una serie reciben números consecutivos', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($product)->quantity('100')->create();

    $dir = sys_get_temp_dir().'/issue-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.5;
    $children = [];

    DB::disconnect();

    foreach (range(1, 6) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            app()->instance(SunatSender::class, new FakeSunatSender);
            time_sleep_until($startAt);

            try {
                [$document] = app(SalesDocumentService::class)->issue([
                    'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
                    'lines' => [['product_id' => $product->id, 'quantity' => '1']],
                ], $seller);
                $result = (string) $document->number;
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
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

    $numbers = array_map('intval', $results);
    sort($numbers);

    expect($numbers)->toBe(range(151, 156), 'Resultados: '.implode(', ', $results))
        ->and(SalesDocument::withoutTenancy()->count())->toBe(6)
        ->and(app(TenantContext::class)->run($company, fn () => $product->fresh()->stock()))->toBe('94.000');
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

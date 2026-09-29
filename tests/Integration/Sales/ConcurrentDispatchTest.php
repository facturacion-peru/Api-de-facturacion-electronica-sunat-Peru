<?php

use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatResponse;
use App\Sunat\Sending\SunatSender;
use App\Sunat\SunatDispatcher;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T052 · RF-012: el reintento manual y el automático a la vez envían el
 * comprobante una sola vez. Solo PostgreSQL y pcntl.
 */

it('varios procesos intentan enviar el mismo comprobante: solo uno lo envía', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($product)->quantity('10')->create();

    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable()));
    [$document] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ], $seller);

    $log = tempnam(sys_get_temp_dir(), 'sunat-sends-');
    $startAt = microtime(true) + 1.5;
    $children = [];

    DB::disconnect();

    foreach (range(1, 5) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            // SUNAT lenta que anota cada envío en un archivo compartido entre procesos.
            app()->instance(SunatSender::class, new class($log) implements SunatSender
            {
                public function __construct(private string $log) {}

                public function send(string $signedXml, string $issuerRuc): SunatResponse
                {
                    file_put_contents($this->log, getmypid().PHP_EOL, FILE_APPEND | LOCK_EX);
                    usleep(300_000);

                    return FakeSunatSender::accepted();
                }
            });
            time_sleep_until($startAt);

            app(SunatDispatcher::class)->send(SalesDocument::find($document->id), $i === 1 ? SubmissionTrigger::Manual : SubmissionTrigger::Scheduled);
            pcntl_exec('/bin/true');
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();
    $sends = array_filter(explode(PHP_EOL, (string) file_get_contents($log)));
    unlink($log);

    expect($sends)->toHaveCount(1)
        ->and(SalesDocument::withoutTenancy()->find($document->id)->status)->toBe(SalesDocumentStatus::Accepted);
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

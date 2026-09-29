<?php

use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionResult;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\SunatSubmission;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T053 · CE-002: tras una caída de SUNAT, el 100 % de los pendientes
 * termina en un estado definitivo, con un solo envío aceptado por comprobante.
 */

it('al restablecerse SUNAT, todos los pendientes terminan aceptados sin duplicados', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($product)->quantity('100')->create();

    // SUNAT caída: 12 ventas quedan pendientes, con su número.
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable()));
    foreach (range(1, 12) as $i) {
        app(SalesDocumentService::class)->issue([
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
            'lines' => [['product_id' => $product->id, 'quantity' => '1']],
        ], $seller);
    }
    $this->travel(1)->minutes();
    $this->artisan('sunat:send-pending')->assertSuccessful(); // sigue caída

    expect(SalesDocument::where('status', SalesDocumentStatus::Pending)->count())->toBe(12);

    // SUNAT vuelve.
    app()->instance(SunatSender::class, $sunat = new FakeSunatSender(FakeSunatSender::accepted()));
    $this->travel(5)->minutes();
    $this->artisan('sunat:send-pending')->assertSuccessful();
    $this->travel(5)->minutes();
    $this->artisan('sunat:send-pending')->assertSuccessful(); // no debe reenviar nada

    $accepted = SunatSubmission::where('result', SubmissionResult::Accepted)->get()->groupBy('sales_document_id');

    expect(SalesDocument::where('status', SalesDocumentStatus::Accepted)->count())->toBe(12)
        ->and($sunat->sent)->toHaveCount(12)
        ->and($accepted)->toHaveCount(12)
        ->and($accepted->every(fn ($s) => $s->count() === 1))->toBeTrue()
        ->and(SalesDocument::orderBy('number')->pluck('number')->all())->toBe(range(151, 162));
});

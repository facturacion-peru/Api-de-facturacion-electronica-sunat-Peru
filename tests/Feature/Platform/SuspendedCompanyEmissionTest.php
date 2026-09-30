<?php

use App\Enums\SalesDocumentStatus;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T033 · Caso límite de la 006: suspender una empresa no detiene los
 * reintentos de sus comprobantes pendientes (la venta ya ocurrió).
 */

it('los pendientes de una empresa suspendida se siguen enviando', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($product)->quantity('5')->create();
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable(), FakeSunatSender::accepted()));
    [$document] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ], $seller);
    app(TenantContext::class)->clear();

    $root = User::factory()->platformAdmin()->create();
    $this->withToken($root->createToken('t')->plainTextToken)
        ->postJson("/api/v1/platform/companies/{$company->id}/deactivate", ['reason' => 'Prueba'])->assertOk();

    $this->travel(2)->minutes();
    $this->artisan('sunat:send-pending')->assertSuccessful();

    expect(SalesDocument::withoutTenancy()->find($document->id)->status)->toBe(SalesDocumentStatus::Accepted);
});

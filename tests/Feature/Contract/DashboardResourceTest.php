<?php

use App\Enums\SalesDocumentStatus;
use App\Models\SalesDocument;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Tests\Support\SalesFixture;

/*
 * Spec 011 · T007 · Contrato de GET /api/v1/dashboard (principio III). El
 * frontend lo tipa a mano en features/dashboard/types.ts.
 */

it('expone exactamente las claves del resumen y de cada venta reciente', function () {
    ['company' => $company, 'admin' => $admin, 'receipt' => $receipt] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    Ticket::factory()->create(['company_id' => $company->id, 'seller_id' => $admin->id]);
    SalesDocument::factory()->status(SalesDocumentStatus::Accepted)->create(['series_id' => $receipt->id, 'seller_id' => $admin->id]);

    $json = $this->withToken($admin->createToken('t')->plainTextToken)->getJson('/api/v1/dashboard')->assertOk()->json();

    expect(array_keys($json))->toBe(['success', 'data'])
        ->and(array_keys($json['data']))->toBe(['date', 'scope', 'today', 'last_7_days', 'attention', 'recent_sales'])
        ->and(array_keys($json['data']['today']))->toBe(['total', 'count'])
        ->and(array_keys($json['data']['last_7_days'][0]))->toBe(['date', 'total', 'count'])
        ->and(array_keys($json['data']['attention']))->toBe(['pending_documents', 'rejected_documents', 'inventory_alerts'])
        ->and($json['data']['recent_sales'])->toHaveCount(2);

    foreach ($json['data']['recent_sales'] as $sale) {
        expect(array_keys($sale))->toBe(['kind', 'id', 'number', 'document_type', 'customer_name', 'total', 'status', 'issued_at']);
    }
});

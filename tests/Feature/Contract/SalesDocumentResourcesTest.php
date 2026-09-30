<?php

use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductLot;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T072 · Contrato de los recursos de la spec 005 (principio III). Si cambia
 * un campo, hay que actualizar también los tipos del frontend.
 */

it('el comprobante, sus líneas e intentos exponen exactamente los campos declarados', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($product)->quantity('5')->create();
    app()->instance(SunatSender::class, new FakeSunatSender);
    [$document] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ], $seller);

    $data = $this->withToken($seller->createToken('t')->plainTextToken)->getJson("/api/v1/sales-documents/{$document->id}")->json('data');

    expect(array_keys($data))->toBe([
        'id', 'document_type', 'document_type_label', 'series_code', 'number', 'display_number', 'environment', 'environment_notice',
        'issued_at', 'seller', 'payment_method', 'currency', 'customer', 'op_gravadas', 'op_exoneradas', 'op_inafectas', 'igv',
        'discount_total', 'total', 'status', 'status_label', 'sunat_code', 'sunat_message', 'sunat_notes', 'hash', 'has_cdr',
        'attempts', 'next_attempt_at', 'can_retry',
        // Spec 007
        'correction_status', 'correction_status_label', 'can_credit', 'note_reason_code', 'note_reason_label', 'note_reason', 'restock',
        'discard_reason', 'reference', 'credit_notes', 'lines', 'submissions',
    ])->and(array_keys($data['customer']))->toBe(['id', 'document_type', 'document_number', 'name', 'address'])
        ->and(array_keys($data['lines'][0]))->toBe([
            'position', 'product_code', 'product_name', 'unit', 'igv_affectation', 'quantity', 'unit_price', 'discount', 'base_amount', 'igv', 'amount', 'remaining',
        ])
        ->and(array_keys($data['submissions'][0]))->toBe(['trigger', 'started_at', 'duration_ms', 'result', 'code', 'message']);
});

it('el cliente expone exactamente los campos declarados', function () {
    $customer = Customer::factory()->create();
    app(TenantContext::class)->set($customer->company);

    expect(array_keys(CustomerResource::make($customer)->resolve()))->toBe([
        'id', 'document_type', 'document_type_label', 'document_number', 'name', 'address',
    ]);
});

it('007 la nota expone su referencia y el comprobante lista sus notas con los campos declarados', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($product)->quantity('5')->create();
    app()->instance(SunatSender::class, new FakeSunatSender);
    [$boleta] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '2']],
    ], $seller);
    [$note] = app(App\Services\CreditNoteService::class)->issue($boleta, [
        'idempotency_key' => (string) Str::uuid(), 'reason_code' => '07', 'reason' => 'Prueba', 'lines' => [['line_position' => 1, 'quantity' => '1']],
    ], $seller);

    $token = $seller->createToken('t')->plainTextToken;
    $noteData = $this->withToken($token)->getJson("/api/v1/sales-documents/{$note->id}")->json('data');
    app('auth')->forgetGuards();
    $boletaData = $this->withToken($token)->getJson("/api/v1/sales-documents/{$boleta->id}")->json('data');

    expect(array_keys($noteData['reference']))->toBe(['id', 'document_type', 'display_number'])
        ->and($noteData['lines'][0]['remaining'])->toBeNull()
        ->and(array_keys($boletaData['credit_notes'][0]))->toBe(['id', 'display_number', 'note_reason_label', 'status', 'status_label', 'total', 'issued_at'])
        ->and($boletaData['lines'][0]['remaining'])->toBe('1.000');
});

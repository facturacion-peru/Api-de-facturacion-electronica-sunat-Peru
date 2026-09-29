<?php

use App\Enums\DocumentType;
use App\Enums\SalesDocumentStatus;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\SalesFixture;

/*
 * T060 · HU-6 Consultar comprobantes (RF-021, A-32).
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller, 'receipt' => $this->receipt, 'invoice' => $this->invoice] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $this->rosa = User::factory()->forCompany($this->company)->create(['name' => 'Rosa']);
    $this->client = Customer::factory()->withRuc()->create(['company_id' => $this->company->id, 'name' => 'FERRETERIA EL SOL S.A.C.']);

    $make = fn (Series $series, SalesDocumentStatus $status, User $seller, string $date, ?Customer $customer = null) => SalesDocument::factory()->status($status)->create([
        'series_id' => $series->id, 'seller_id' => $seller->id, 'issued_at' => $date,
        ...($customer ? ['customer_id' => $customer->id, 'customer_document_type' => '6', 'customer_document_number' => $customer->document_number, 'customer_name' => $customer->name] : []),
    ]);
    $this->accepted = $make($this->receipt, SalesDocumentStatus::Accepted, $this->seller, '2026-09-20 10:00:00');
    $this->pending = $make($this->receipt, SalesDocumentStatus::Pending, $this->rosa, '2026-09-25 11:00:00');
    $this->rejected = $make($this->invoice, SalesDocumentStatus::Rejected, $this->rosa, '2026-09-28 12:00:00', $this->client);

    $this->list = function (string $query = '', ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)->getJson('/api/v1/sales-documents'.$query);
    };
});

it('A-32 el vendedor ve todos los comprobantes de su empresa, del más reciente al más antiguo', function () {
    $ids = array_column(($this->list)()->assertOk()->json('data'), 'id');

    expect($ids)->toBe([$this->rejected->id, $this->pending->id, $this->accepted->id]);
});

it('HU-6.1 filtra por estado para actuar sobre pendientes y rechazados', function () {
    expect(array_column(($this->list)('?status=pending')->json('data'), 'id'))->toBe([$this->pending->id])
        ->and(array_column(($this->list)('?status=rejected')->json('data'), 'id'))->toBe([$this->rejected->id]);
});

it('filtra por fecha, tipo y cliente', function () {
    expect(array_column(($this->list)('?from=2026-09-21&to=2026-09-26')->json('data'), 'id'))->toBe([$this->pending->id])
        ->and(array_column(($this->list)('?document_type=01')->json('data'), 'id'))->toBe([$this->rejected->id])
        ->and(array_column(($this->list)('?customer=ferreteria')->json('data'), 'id'))->toBe([$this->rejected->id])
        ->and(array_column(($this->list)('?customer='.$this->client->document_number)->json('data'), 'id'))->toBe([$this->rejected->id]);
});

it('cuenta los pendientes y rechazados para avisar en la lista', function () {
    ($this->list)('?status=accepted')->assertOk()->assertJsonPath('counts.pending', 1)->assertJsonPath('counts.rejected', 1);
});

it('valida los filtros', function () {
    ($this->list)('?status=perdido&document_type=07')->assertStatus(422)->assertJsonValidationErrors(['status', 'document_type']);
});

it('el detalle trae líneas, totales, mensaje de SUNAT e intentos', function () {
    app('auth')->forgetGuards();

    $data = $this->withToken($this->rosa->createToken('t')->plainTextToken)
        ->getJson("/api/v1/sales-documents/{$this->accepted->id}")->assertOk()->json('data');

    expect($data)->toHaveKeys(['lines', 'submissions', 'op_gravadas', 'op_exoneradas', 'op_inafectas', 'igv', 'total', 'sunat_message', 'sunat_notes', 'environment_notice'])
        ->and($data['document_type'])->toBe(DocumentType::Receipt->value)
        ->and($data['seller']['id'])->toBe($this->seller->id);
});

<?php

use App\Enums\CustomerDocumentType;
use App\Models\Customer;
use App\Models\DocumentLookup;
use App\Models\Product;
use App\Models\ProductLot;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\ImportFiles;
use Tests\Support\SalesFixture;

/*
 * T008 · HU-2, RF-002, RF-012, A-92, A-95 (spec 018): un cliente verificado
 * no se cambia a mano ni con una importación; un RUC de baja deja emitir.
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller, 'invoice' => $this->invoice] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $this->verified = Customer::factory()->withRuc()->create(['company_id' => $this->company->id, 'name' => 'EMPRESA VERIFICADA S.A.C.', 'address' => 'AV. OFICIAL 1', 'verified_at' => now()]);
    $this->manual = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Cliente manual']);
    $this->call = function (string $method, string $uri, array $data = []) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
});

it('el nombre y la dirección de un verificado no se editan a mano', function (array $data, string $field) {
    ($this->call)('PATCH', "/api/v1/customers/{$this->verified->id}", $data)
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$field}.0", 'Cliente verificado: sus datos vienen de SUNAT o RENIEC. Usa «Actualizar datos».');

    expect($this->verified->fresh()->only(['name', 'address']))->toBe(['name' => 'EMPRESA VERIFICADA S.A.C.', 'address' => 'AV. OFICIAL 1']);
})->with([
    'nombre' => [['name' => 'Otro nombre'], 'name'],
    'dirección' => [['address' => 'Otra dirección'], 'address'],
]);

it('mandar los mismos datos de un verificado no es un error', function () {
    ($this->call)('PATCH', "/api/v1/customers/{$this->verified->id}", ['name' => 'EMPRESA VERIFICADA S.A.C.', 'address' => 'AV. OFICIAL 1'])->assertOk();
});

it('un cliente manual se sigue editando', function () {
    ($this->call)('PATCH', "/api/v1/customers/{$this->manual->id}", ['name' => 'Nombre corregido'])->assertOk()->assertJsonPath('data.name', 'Nombre corregido');
});

it('RF-012: la importación no cambia un verificado y lo avisa', function () {
    $file = ImportFiles::make(['tipo_documento', 'numero_documento', 'nombre', 'direccion'], [
        ['RUC', $this->verified->document_number, 'Nombre del Excel', 'Dirección del Excel'],
        ['DNI', $this->manual->document_number, 'Manual desde Excel', ''],
    ]);

    $preview = ($this->call)('POST', '/api/v1/imports/customers/preview', ['file' => $file, 'mode' => 'upsert'])->assertCreated();
    ($this->call)('POST', '/api/v1/imports/'.$preview->json('data.id').'/confirm')->assertOk();

    expect($preview->json('data.warnings'))->toContain(['row' => 2, 'column' => 'nombre', 'message' => 'Cliente verificado: no se cambia su nombre ni dirección.'])
        ->and($this->verified->fresh()->only(['name', 'address']))->toBe(['name' => 'EMPRESA VERIFICADA S.A.C.', 'address' => 'AV. OFICIAL 1'])
        ->and($this->manual->fresh()->name)->toBe('Manual desde Excel');
});

it('A-95: un RUC de baja o no habido muestra su estado y la factura se emite', function () {
    DocumentLookup::create(['document_type' => CustomerDocumentType::Ruc, 'document_number' => $this->verified->document_number, 'name' => $this->verified->name,
        'ruc_status' => 'BAJA DE OFICIO', 'ruc_condition' => 'NO HABIDO', 'fetched_at' => now()]);
    app()->instance(SunatSender::class, new FakeSunatSender);
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('5')->create();

    ($this->call)('GET', '/api/v1/customers?search='.$this->verified->document_number)->assertOk()
        ->assertJsonPath('data.0.ruc_status', 'BAJA DE OFICIO')->assertJsonPath('data.0.ruc_condition', 'NO HABIDO');

    app('auth')->forgetGuards();
    $this->withToken($this->seller->createToken('t')->plainTextToken)->postJson('/api/v1/sales-documents', [
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '01', 'series_id' => $this->invoice->id, 'payment_method' => 'cash',
        'customer_id' => $this->verified->id, 'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ])->assertCreated()->assertJsonPath('data.status', 'accepted');
});

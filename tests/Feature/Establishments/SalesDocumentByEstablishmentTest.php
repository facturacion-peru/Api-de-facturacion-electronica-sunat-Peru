<?php

use App\Enums\DocumentType;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T017 · RF-002, RF-003, RF-004, HU-2 esc. 3 y 4 (spec 017): el comprobante
 * es del local de su serie; la nota, del local del comprobante original.
 */

beforeEach(function () {
    $f = SalesFixture::issuable();
    ['company' => $this->company, 'admin' => $this->admin] = $f;
    $this->fixture = $f;
    app(TenantContext::class)->set($this->company);
    $this->main = Establishment::where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001', 'name' => 'Sucursal Centro', 'address' => 'JR. UNION 456']);
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->seller->establishments()->attach($this->branch->id, ['company_id' => $this->company->id]);
    $this->branchReceipt = Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->branch->id, 'code' => 'B002']);

    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'sale_price' => '10.00', 'igv_affectation' => '10']);
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id]);
    ProductLot::factory()->for($this->product)->quantity('5')->create(['establishment_id' => $this->branch->id]);
    $this->stock = fn (Establishment $e) => app(InventoryService::class)->balance($this->product, $e);

    $this->sunat = new FakeSunatSender;
    app()->instance(SunatSender::class, $this->sunat);

    $this->issue = function (array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)->postJson('/api/v1/sales-documents', [
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '2']], ...$data,
        ]);
    };
});

it('la boleta con serie del segundo local lleva su código de anexo y su dirección, y descuenta allí', function () {
    $response = ($this->issue)(['series_id' => $this->branchReceipt->id])->assertCreated()
        ->assertJsonPath('data.establishment.code', '0001')
        ->assertJsonPath('data.establishment.address', 'JR. UNION 456');

    $document = SalesDocument::findOrFail($response->json('data.id'));
    expect($document->establishment_id)->toBe($this->branch->id)
        ->and($document->issuer_address)->toBe('JR. UNION 456')
        ->and($document->xml)->toContain('<cbc:AddressTypeCode>0001</cbc:AddressTypeCode>')
        ->and($document->xml)->toContain('JR. UNION 456')
        ->and(($this->stock)($this->branch))->toBe('3.000')
        ->and(($this->stock)($this->main))->toBe('10.000');
});

it('sin serie elige entre las del local resuelto', function () {
    ($this->issue)()->assertCreated()->assertJsonPath('data.series_code', 'B002');
});

it('el administrador con varios locales elige el local o la serie', function () {
    ($this->issue)([], $this->admin)->assertUnprocessable()->assertJsonPath('errors.establishment_id.0', 'Elige el local.');
    ($this->issue)(['establishment_id' => $this->main->id], $this->admin)->assertCreated()->assertJsonPath('data.series_code', 'B001');
    ($this->issue)(['series_id' => $this->branchReceipt->id], $this->admin)->assertCreated()->assertJsonPath('data.establishment.code', '0001');
});

it('un local distinto al de la serie da 422', function () {
    ($this->issue)(['series_id' => $this->branchReceipt->id, 'establishment_id' => $this->main->id], $this->admin)
        ->assertUnprocessable()->assertJsonPath('errors.establishment_id.0', 'La serie B002 es de otro local.');
});

it('una serie de un local no permitido o inactivo da 422 sin emitir', function () {
    ($this->issue)(['series_id' => $this->fixture['receipt']->id])
        ->assertUnprocessable()->assertJsonPath('errors.series_id.0', 'La serie B001 es de un local donde no puedes trabajar.');

    $this->branch->update(['active' => false]);
    ($this->issue)(['series_id' => $this->branchReceipt->id], $this->admin)
        ->assertUnprocessable()->assertJsonPath('errors.series_id.0', 'La serie B002 es de un local donde no puedes trabajar.');

    expect(SalesDocument::count())->toBe(0);
});

it('la nota usa una serie de notas del local del original y repone allí', function () {
    $noteSeries = Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->branch->id, 'code' => 'BC02', 'document_type' => DocumentType::CreditNote]);
    $original = ($this->issue)(['series_id' => $this->branchReceipt->id])->assertCreated()->json('data.id');
    app('auth')->forgetGuards();

    $note = $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->postJson("/api/v1/sales-documents/{$original}/credit-notes", ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Devolución', 'reason_code' => '06'])
        ->assertCreated()
        ->assertJsonPath('data.series_code', 'BC02')
        ->assertJsonPath('data.establishment.code', '0001');

    expect(SalesDocument::findOrFail($note->json('data.id'))->xml)->toContain('<cbc:AddressTypeCode>0001</cbc:AddressTypeCode>')
        ->and(($this->stock)($this->branch))->toBe('5.000')
        ->and($noteSeries->fresh()->last_number)->toBe(1);
});

it('una serie de notas de otro local no sirve para el original', function () {
    $original = ($this->issue)(['series_id' => $this->branchReceipt->id])->assertCreated()->json('data.id');
    app('auth')->forgetGuards();

    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->postJson("/api/v1/sales-documents/{$original}/credit-notes", ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Devolución', 'reason_code' => '06'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.series_id.0', 'No hay una serie de notas de crédito de boletas activa en Sucursal Centro (B…). Pide al administrador que cree una, p. ej. BC01.');

    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->postJson("/api/v1/sales-documents/{$original}/credit-notes", ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Devolución', 'reason_code' => '06', 'series_id' => $this->fixture['receiptNote']->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['series_id']);
});

it('descartar un rechazado repone en su local', function () {
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::rejected()));
    $original = ($this->issue)(['series_id' => $this->branchReceipt->id])->assertCreated()->assertJsonPath('data.status', 'rejected')->json('data.id');
    app('auth')->forgetGuards();

    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->postJson("/api/v1/sales-documents/{$original}/discard", ['reason' => 'Rechazado por SUNAT'])->assertOk();

    expect(($this->stock)($this->branch))->toBe('5.000');
});

it('la serie se crea en el local indicado, por defecto el principal, y lo informa', function () {
    app('auth')->forgetGuards();
    $call = fn (array $data) => $this->withToken($this->admin->createToken('t')->plainTextToken)->postJson('/api/v1/series', $data);

    $call(['document_type' => '03', 'code' => 'B003', 'establishment_id' => $this->branch->id])->assertCreated()->assertJsonPath('data.establishment_id', $this->branch->id);
    app('auth')->forgetGuards();
    $call(['document_type' => '03', 'code' => 'B004'])->assertCreated()->assertJsonPath('data.establishment_id', $this->main->id);

    $this->branch->update(['active' => false]);
    app('auth')->forgetGuards();
    $call(['document_type' => '03', 'code' => 'B005', 'establishment_id' => $this->branch->id])->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);
});

it('el vendedor solo lista las series de sus locales; el administrador filtra', function () {
    app('auth')->forgetGuards();
    $seller = collect($this->withToken($this->seller->createToken('t')->plainTextToken)->getJson('/api/v1/series')->assertOk()->json('data'))->pluck('code')->all();
    app('auth')->forgetGuards();
    $branch = collect($this->withToken($this->admin->createToken('t')->plainTextToken)->getJson('/api/v1/series?establishment_id='.$this->branch->id)->assertOk()->json('data'))->pluck('code')->all();

    expect($seller)->toBe(['B002'])->and($branch)->toBe(['B002']);
});

it('la emisión por el servicio mantiene la idempotencia por clave', function () {
    $key = (string) Str::uuid();
    $data = ['idempotency_key' => $key, 'document_type' => '03', 'payment_method' => 'cash', 'series_id' => $this->branchReceipt->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '1']]];

    [$first] = app(SalesDocumentService::class)->issue($data, $this->seller);
    [$again, $created] = app(SalesDocumentService::class)->issue($data, $this->seller);

    expect($again->id)->toBe($first->id)->and($created)->toBeFalse();
});

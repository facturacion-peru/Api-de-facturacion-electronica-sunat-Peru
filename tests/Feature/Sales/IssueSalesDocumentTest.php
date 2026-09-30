<?php

use App\Enums\MovementType;
use App\Enums\SunatStatus;
use App\Enums\TaxRegime;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\SunatSetting;
use App\Models\User;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T041 · HU-1 y HU-2 Emitir boletas y facturas (RF-001 a RF-009, HU-4.1).
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller, 'receipt' => $this->receipt, 'invoice' => $this->invoice] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);

    $this->arroz = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'ARZ-1', 'name' => 'Arroz 5 kg', 'sale_price' => '25.90', 'igv_affectation' => '10']);
    ProductLot::factory()->for($this->arroz)->quantity('50')->create();
    $this->leche = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'LEC-1', 'name' => 'Leche', 'sale_price' => '4.50', 'igv_affectation' => '20']);
    ProductLot::factory()->for($this->leche)->quantity('20')->create();
    $this->ruc = Customer::factory()->withRuc()->create(['company_id' => $this->company->id]);
    $this->dni = Customer::factory()->create(['company_id' => $this->company->id, 'document_number' => '46027897', 'name' => 'MARIA QUISPE']);

    $this->sunat = new FakeSunatSender;
    app()->instance(SunatSender::class, $this->sunat);

    $this->issue = function (array $data, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)->postJson('/api/v1/sales-documents', [
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->arroz->id, 'quantity' => '2'], ['product_id' => $this->leche->id, 'quantity' => '3']],
            ...$data,
        ]);
    };
});

it('HU-1.1 emite la boleta: correlativo, stock, XML firmado y envío', function () {
    $response = ($this->issue)([]);

    $response->assertCreated()
        ->assertJsonPath('data.display_number', 'B001-00000151')
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.environment_notice', 'PRUEBAS — sin valor legal')
        ->assertJsonPath('data.op_gravadas', '43.90')
        ->assertJsonPath('data.op_exoneradas', '13.50')
        ->assertJsonPath('data.igv', '7.90')
        ->assertJsonPath('data.total', '65.30')
        ->assertJsonPath('data.customer.name', 'CLIENTES VARIOS')
        ->assertJsonPath('data.seller.name', 'Luis');

    $document = SalesDocument::sole();
    expect($this->receipt->fresh()->last_number)->toBe(151)
        ->and($document->xml)->toContain('<ds:DigestValue>'.$document->hash)
        ->and($document->cdr)->toBe(base64_encode('CDR-ZIP'))
        ->and($this->sunat->sent)->toHaveCount(1)
        ->and($this->arroz->stock())->toBe('48.000')
        ->and(InventoryMovement::where('type', MovementType::Sale)->where('source_type', $document->getMorphClass())->where('source_id', $document->id)->count())->toBe(2);
});

it('HU-1.2 hasta S/ 700 sale a Cliente varios; por encima exige el documento', function () {
    ProductLot::factory()->for($this->arroz)->quantity('100')->create();

    ($this->issue)(['lines' => [['product_id' => $this->arroz->id, 'quantity' => '27']]])->assertCreated(); // 699.30
    ($this->issue)(['lines' => [['product_id' => $this->arroz->id, 'quantity' => '28']]]) // 725.20
        ->assertStatus(422)->assertJsonPath('errors.customer_id.0', 'Las boletas de más de S/ 700 requieren el documento del comprador.');
    ($this->issue)(['customer_id' => $this->dni->id, 'lines' => [['product_id' => $this->arroz->id, 'quantity' => '28']]])
        ->assertCreated()->assertJsonPath('data.customer.document_number', '46027897');
});

it('HU-2.1 emite la factura a un cliente con RUC, con su serie y forma de pago contado', function () {
    $response = ($this->issue)(['document_type' => '01', 'customer_id' => $this->ruc->id]);

    $response->assertCreated()->assertJsonPath('data.display_number', 'F001-00000001')->assertJsonPath('data.customer.document_type', '6');
    expect(SalesDocument::sole()->xml)->toContain('Contado');
});

it('HU-2.2 la factura exige un cliente con RUC y sugiere boleta', function (?string $customer) {
    ($this->issue)(['document_type' => '01', 'customer_id' => $customer ? $this->{$customer}->id : null])
        ->assertStatus(422)->assertJsonPath('errors.customer_id.0', 'La factura requiere un cliente con RUC. Para este cliente, emite una boleta.');
})->with([null, 'dni']);

it('HU-2.3 el Nuevo RUS no emite facturas', function () {
    $this->company->update(['tax_regime' => TaxRegime::Nrus]);

    ($this->issue)(['document_type' => '01', 'customer_id' => $this->ruc->id])
        ->assertStatus(422)->assertJsonPath('errors.document_type.0', 'Las empresas del Nuevo RUS no emiten facturas.');
});

it('RF-002 sin la configuración SUNAT validada no emite', function () {
    SunatSetting::where('company_id', $this->company->id)->update(['status' => SunatStatus::Pending]);

    ($this->issue)([])->assertStatus(422)->assertJsonPath('errors.sunat.0', 'La emisión SUNAT no está disponible: Pendiente de validación.');
    expect(SalesDocument::count())->toBe(0);
});

it('RF-002 exige una serie activa del tipo', function () {
    $this->receipt->update(['active' => false]);

    ($this->issue)([])->assertStatus(422)->assertJsonPath('errors.series_id.0', 'No hay una serie de Boleta de venta activa. Pide al administrador que cree o active una.');
    ($this->issue)(['series_id' => $this->receipt->id])->assertStatus(422)->assertJsonPath('errors.series_id.0', 'La serie B001 está desactivada.');
    ($this->issue)(['series_id' => $this->invoice->id])->assertStatus(422)->assertJsonPath('errors.series_id.0', 'La serie F001 no es de Boleta de venta.');
});

it('RF-002 con varias series activas del tipo pide elegir', function () {
    $b002 = Series::factory()->create(['company_id' => $this->company->id, 'code' => 'B002']);

    ($this->issue)([])->assertStatus(422)->assertJsonPath('errors.series_id.0', 'Elige la serie: B001, B002.');
    ($this->issue)(['series_id' => $b002->id])->assertCreated()->assertJsonPath('data.display_number', 'B002-00000001');
});

it('RF-005 sin stock no guarda nada ni consume el correlativo', function () {
    ($this->issue)(['customer_id' => $this->dni->id, 'lines' => [['product_id' => $this->arroz->id, 'quantity' => '500']]])
        ->assertStatus(422)->assertJsonPath('meta.available', '50.000');

    expect(SalesDocument::count())->toBe(0)->and($this->receipt->fresh()->last_number)->toBe(150)->and($this->sunat->sent)->toBe([]);
});

it('RF-005 si la firma falla no guarda nada ni consume el correlativo', function () {
    Certificate::where('company_id', $this->company->id)->update(['pem' => encrypt('no es un PEM', false)]);

    ($this->issue)([])->assertStatus(422)->assertJsonPath('errors.sunat.0', 'No se pudo firmar el comprobante con el certificado registrado. Revisa la configuración SUNAT.');

    expect(SalesDocument::count())->toBe(0)->and($this->receipt->fresh()->last_number)->toBe(150)->and($this->arroz->stock())->toBe('50.000');
});

it('RF-006 copia los datos del emisor, del cliente y de los productos', function () {
    ($this->issue)(['document_type' => '01', 'customer_id' => $this->ruc->id])->assertCreated();
    $this->ruc->update(['name' => 'NOMBRE NUEVO']);
    $this->arroz->update(['name' => 'Arroz renombrado', 'sale_price' => '30.00']);

    $document = SalesDocument::with('lines')->sole();
    expect($document->issuer_ruc)->toBe($this->company->ruc)
        ->and([$document->issuer_department, $document->issuer_province, $document->issuer_district])->toBe(['Lima', 'Lima', 'Lima'])
        ->and($document->customer_name)->not->toBe('NOMBRE NUEVO')
        ->and($document->lines[0]->product_name)->toBe('Arroz 5 kg')
        ->and($document->lines[0]->unit_price)->toBe('25.90');
});

it('la misma clave de idempotencia devuelve el mismo comprobante sin reenviar', function () {
    $key = (string) Str::uuid();

    $first = ($this->issue)(['idempotency_key' => $key])->assertCreated();
    ($this->issue)(['idempotency_key' => $key])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

    expect(SalesDocument::count())->toBe(1)->and($this->sunat->sent)->toHaveCount(1);
});

it('HU-4.1 si SUNAT no responde queda pendiente con su número y se informa', function () {
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable()));

    ($this->issue)([])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.status_label', 'Pendiente de envío')
        ->assertJsonPath('data.display_number', 'B001-00000151');

    expect(SalesDocument::sole()->next_attempt_at)->not->toBeNull();
});

it('valida las líneas y el medio de pago', function () {
    ($this->issue)(['lines' => [], 'payment_method' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors(['lines', 'payment_method']);
});

it('audita la emisión', function () {
    ($this->issue)([])->assertCreated();

    $log = AuditLog::where('action', 'sales_document.issued')->sole();
    expect($log->changes)->toMatchArray(['number' => 'B001-00000151', 'total' => '65.30']);
});

it('la nota de crédito no se emite por esta ruta, sino desde su comprobante (spec 007)', function () {
    ($this->issue)(['document_type' => '07'])->assertStatus(422)->assertJsonValidationErrors(['document_type']);
});

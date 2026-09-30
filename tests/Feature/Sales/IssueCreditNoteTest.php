<?php

use App\Enums\CorrectionStatus;
use App\Enums\MovementType;
use App\Enums\SalesDocumentStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryMovement;
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
 * T031 · HU-1 y HU-2 Notas de crédito: anulación y devolución (A-39 a A-41).
 */

beforeEach(function () {
    $f = SalesFixture::issuable();
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller] = $f;
    $this->fixture = $f;
    app(TenantContext::class)->set($this->company);

    $this->yogur = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Yogur', 'sale_price' => '7.50', 'igv_affectation' => '10']);
    $this->lot = ProductLot::factory()->for($this->yogur)->quantity('20')->create();
    $this->leche = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Leche', 'sale_price' => '4.50', 'igv_affectation' => '20']);
    ProductLot::factory()->for($this->leche)->quantity('20')->create();

    $this->sunat = new FakeSunatSender;
    app()->instance(SunatSender::class, $this->sunat);

    // Boleta: 4 yogures con 1.00 de descuento (29.00) + 3 leches exoneradas (13.50) = 42.50.
    [$this->boleta] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $this->yogur->id, 'quantity' => '4', 'discount' => '1.00'], ['product_id' => $this->leche->id, 'quantity' => '3']],
    ], $this->seller);

    $this->note = function (array $data, ?User $as = null, ?SalesDocument $on = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)
            ->postJson('/api/v1/sales-documents/'.($on ?? $this->boleta)->id.'/credit-notes', ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Motivo de prueba', ...$data]);
    };
    $this->stock = fn (Product $p) => $p->fresh()->stock();
});

it('HU-1 anula la boleta con una nota por el total, repone el stock y la marca anulada', function () {
    $response = ($this->note)(['reason_code' => '01', 'reason' => 'El cliente se arrepintió en caja']);

    $response->assertCreated()
        ->assertJsonPath('data.document_type', '07')
        ->assertJsonPath('data.display_number', 'BC01-00000001')
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.total', '42.50')
        ->assertJsonPath('data.reference.display_number', $this->boleta->display_number)
        ->assertJsonPath('data.note_reason_code', '01');

    expect($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::Voided)
        ->and(($this->stock)($this->yogur))->toBe('20.000')
        ->and(($this->stock)($this->leche))->toBe('20.000');
});

it('HU-1.2 anular sin que vuelva la mercadería no repone el stock', function () {
    ($this->note)(['reason_code' => '01', 'restock' => false])->assertCreated();

    expect(($this->stock)($this->yogur))->toBe('16.000')
        ->and($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::Voided);
});

it('HU-1.3 no se anula dos veces', function () {
    ($this->note)(['reason_code' => '01'])->assertCreated();

    ($this->note)(['reason_code' => '01'])->assertStatus(422)->assertJsonPath('errors.sales_document.0', 'Este comprobante ya fue anulado o devuelto totalmente.');
});

it('HU-2.1 devolución parcial: importes prorrateados y stock repuesto en el lote original', function () {
    $response = ($this->note)(['reason_code' => '07', 'lines' => [['line_position' => 1, 'quantity' => '1']]]);

    $response->assertCreated()->assertJsonPath('data.total', '7.25')->assertJsonPath('data.lines.0.discount', '0.25');
    expect($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::PartiallyReturned)
        ->and($this->lot->fresh()->remaining_quantity)->toBe('17.000')
        ->and(InventoryMovement::where('type', MovementType::Return)->count())->toBe(1);
});

it('HU-2.2/2.3 devoluciones sucesivas no superan lo emitido y la total cubre el resto', function () {
    ($this->note)(['reason_code' => '07', 'lines' => [['line_position' => 1, 'quantity' => '1']]])->assertCreated();
    // Errores por línea con clave plana ("lines.0.quantity"), como en la 005.
    $tooMany = ($this->note)(['reason_code' => '07', 'lines' => [['line_position' => 1, 'quantity' => '4']]])->assertStatus(422);
    expect($tooMany->json('errors')['lines.0.quantity'][0])->toBe('Solo quedan 3 por devolver de esta línea.');

    $total = ($this->note)(['reason_code' => '06'])->assertCreated();

    expect($total->json('data.total'))->toBe('35.25')
        ->and($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::FullyReturned)
        ->and(SalesDocument::where('reference_document_id', $this->boleta->id)->get()->reduce(fn ($c, $n) => bcadd($c, $n->total, 2), '0'))->toBe('42.50')
        ->and(($this->stock)($this->yogur))->toBe('20.000');
});

it('la devolución por ítem exige líneas y no admite posiciones inexistentes', function () {
    ($this->note)(['reason_code' => '07'])->assertStatus(422)->assertJsonValidationErrors(['lines']);
    $missing = ($this->note)(['reason_code' => '07', 'lines' => [['line_position' => 9, 'quantity' => '1']]])->assertStatus(422);
    expect($missing->json('errors')['lines.0.line_position'][0])->toBe('El comprobante no tiene la línea 9.');
});

it('devuelve un producto que ya no está activo en el catálogo', function () {
    $this->yogur->update(['active' => false]);

    ($this->note)(['reason_code' => '07', 'lines' => [['line_position' => 1, 'quantity' => '2']]])->assertCreated();
    expect(($this->stock)($this->yogur))->toBe('18.000');
});

it('A-41 el vendedor también emite notas de crédito', function () {
    ($this->note)(['reason_code' => '07', 'lines' => [['line_position' => 2, 'quantity' => '1']]], $this->seller)->assertCreated();
});

it('exige una serie de nota activa con la letra del comprobante', function () {
    ($this->note)(['reason_code' => '01', 'series_id' => $this->fixture['invoiceNote']->id])
        ->assertStatus(422)->assertJsonPath('errors.series_id.0', 'La serie FC01 no corresponde a notas de boletas.');

    $this->fixture['receiptNote']->update(['active' => false]);
    ($this->note)(['reason_code' => '01'])
        ->assertStatus(422)->assertJsonPath('errors.series_id.0', 'No hay una serie de notas de crédito de boletas activa (B…). Pide al administrador que cree una, p. ej. BC01.');
});

it('la misma clave de idempotencia devuelve la misma nota', function () {
    $key = (string) Str::uuid();

    $first = ($this->note)(['reason_code' => '01', 'idempotency_key' => $key])->assertCreated();
    ($this->note)(['reason_code' => '01', 'idempotency_key' => $key])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
});

it('no admite notas sobre comprobantes sin aceptar ni sobre otras notas', function (SalesDocumentStatus $status) {
    SalesDocument::whereKey($this->boleta->id)->update(['status' => $status->value]);

    ($this->note)(['reason_code' => '01'])->assertStatus(422)->assertJsonPath('errors.sales_document.0', 'Solo se emiten notas de crédito sobre comprobantes aceptados por SUNAT.');
})->with([SalesDocumentStatus::Pending, SalesDocumentStatus::Rejected, SalesDocumentStatus::Discarded]);

it('si SUNAT no responde, la nota queda pendiente y el comprobante no cambia', function () {
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable()));

    ($this->note)(['reason_code' => '01'])->assertCreated()->assertJsonPath('data.status', 'pending');

    expect($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::None)
        ->and(($this->stock)($this->yogur))->toBe('20.000'); // la reposición va con la nota
});

it('también corrige facturas, con su serie de nota', function () {
    $customer = Customer::factory()->withRuc()->create(['company_id' => $this->company->id]);
    [$factura] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '01', 'customer_id' => $customer->id, 'payment_method' => 'cash',
        'lines' => [['product_id' => $this->yogur->id, 'quantity' => '2']],
    ], $this->seller);

    ($this->note)(['reason_code' => '06'], on: $factura)->assertCreated()->assertJsonPath('data.display_number', 'FC01-00000001');
});

it('audita la nota con lo repuesto', function () {
    ($this->note)(['reason_code' => '07', 'lines' => [['line_position' => 1, 'quantity' => '1']]])->assertCreated();

    $log = AuditLog::where('action', 'credit_note.issued')->sole();
    expect($log->changes)->toMatchArray(['reference' => $this->boleta->display_number, 'reason_code' => '07', 'total' => '7.25', 'restock' => true]);
});

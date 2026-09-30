<?php

use App\Enums\CorrectionStatus;
use App\Enums\MovementType;
use App\Enums\SalesDocumentStatus;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Services\CreditNoteService;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T033 · Efecto del estado final de una nota (spec 007, casos límite).
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($this->product)->quantity('10')->create();
    app()->instance(SunatSender::class, new FakeSunatSender);
    [$this->boleta] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '4']],
    ], $this->seller);

    $this->noteWith = function (FakeSunatSender $sunat, string $reason = '07', array $lines = [['line_position' => 1, 'quantity' => '1']]) {
        app()->instance(SunatSender::class, $sunat);
        [$note] = app(CreditNoteService::class)->issue($this->boleta, [
            'idempotency_key' => (string) Str::uuid(), 'reason_code' => $reason, 'reason' => 'Prueba', 'lines' => $lines,
        ], $this->seller);

        return $note;
    };
});

it('una nota rechazada deshace su reposición y no cambia el comprobante', function () {
    $note = ($this->noteWith)(new FakeSunatSender(FakeSunatSender::rejected()));

    expect($note->status)->toBe(SalesDocumentStatus::Rejected)
        ->and($this->product->fresh()->stock())->toBe('6.000')
        ->and(InventoryMovement::where('type', MovementType::Reversal)->count())->toBe(1)
        ->and($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::None);
});

it('lo de una nota rechazada vuelve a estar disponible para devolver', function () {
    ($this->noteWith)(new FakeSunatSender(FakeSunatSender::rejected()), '06', []);

    $again = ($this->noteWith)(new FakeSunatSender, '06', []);

    expect($again->total)->toBe('40.00')->and($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::FullyReturned);
});

it('una nota pendiente que SUNAT acepta después actualiza el comprobante', function () {
    $note = ($this->noteWith)(new FakeSunatSender(FakeSunatSender::unreachable(), FakeSunatSender::accepted()));
    expect($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::None);

    $this->travel(2)->minutes();
    $this->artisan('sunat:send-pending')->assertSuccessful();

    expect(SalesDocument::find($note->id)->status)->toBe(SalesDocumentStatus::Accepted)
        ->and($this->boleta->fresh()->correction_status)->toBe(CorrectionStatus::PartiallyReturned);
});

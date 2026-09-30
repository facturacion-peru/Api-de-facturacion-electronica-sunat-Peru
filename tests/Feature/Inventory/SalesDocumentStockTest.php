<?php

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Services\InventoryService;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T030 · Stock de comprobantes (spec 007): la venta de un comprobante no se
 * revierte a mano (hallazgo del plan) y la reposición de una nota vuelve a
 * los lotes de los que salió la venta (A-40).
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'sale_price' => '10.00']);
    $this->old = ProductLot::factory()->for($this->product)->quantity('3')->create(['received_at' => now()->subDays(5)]);
    $this->new = ProductLot::factory()->for($this->product)->quantity('10')->create(['received_at' => now()->subDay()]);
    app()->instance(SunatSender::class, new FakeSunatSender);

    // Vende 5: 3 del lote antiguo (FIFO) y 2 del nuevo.
    [$this->document] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '5']],
    ], $this->seller);
    // Hace de «nota» para las pruebas del servicio de inventario.
    $this->note = SalesDocument::factory()->create(['series_id' => $this->document->series_id, 'reference_document_id' => $this->document->id]);
});

it('no permite revertir a mano la venta de un comprobante', function () {
    $sale = InventoryMovement::where('source_id', $this->document->id)->where('type', MovementType::Sale)->first();

    expect(fn () => app(InventoryService::class)->reverse($sale, AdjustmentReason::Error, null, $this->admin))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect($this->product->stock())->toBe('8.000');
});

it('repone en orden inverso a como salió la venta, en los mismos lotes', function () {
    DB::transaction(fn () => app(InventoryService::class)->restock($this->product, '3', $this->document, $this->note, $this->admin));

    expect($this->new->fresh()->remaining_quantity)->toBe('10.000')
        ->and($this->old->fresh()->remaining_quantity)->toBe('1.000')
        ->and(InventoryMovement::where('type', MovementType::Return)->where('source_id', $this->note->id)->count())->toBe(2);
});

it('no repone en un lote más de lo que salió de él', function () {
    $service = app(InventoryService::class);
    DB::transaction(fn () => $service->restock($this->product, '2', $this->document, $this->note, $this->admin));
    DB::transaction(fn () => $service->restock($this->product, '3', $this->document, $this->note, $this->admin));

    expect($this->new->fresh()->remaining_quantity)->toBe('10.000')
        ->and($this->old->fresh()->remaining_quantity)->toBe('3.000')
        ->and(fn () => DB::transaction(fn () => $service->restock($this->product, '1', $this->document, $this->note, $this->admin)))
        ->toThrow(InvalidArgumentException::class);
});

it('la reposición de una nota tampoco se revierte a mano', function () {
    DB::transaction(fn () => app(InventoryService::class)->restock($this->product, '1', $this->document, $this->note, $this->admin));
    $return = InventoryMovement::where('type', MovementType::Return)->first();

    expect(fn () => app(InventoryService::class)->reverse($return, AdjustmentReason::Error, null, $this->admin))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

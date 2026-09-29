<?php

use App\Enums\AdjustmentReason;
use App\Enums\CompanyRole;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/*
 * T050 · HU-4 Ajustes y correcciones (RF-016).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    $this->lot = ProductLot::factory()->for($this->product)->quantity('10')->create();
    $this->entry = InventoryMovement::withoutTenancy()->where('lot_id', $this->lot->id)->sole();
    $this->post = function (string $uri, array $data, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->postJson($uri, $data);
    };
});

it('HU-4.1 ajuste negativo por merma con motivo', function () {
    ($this->post)("/api/v1/lots/{$this->lot->id}/adjustments", ['quantity' => '-2', 'reason' => 'shrinkage', 'note' => 'Bolsas rotas'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'adjustment')
        ->assertJsonPath('data.quantity', '-2.000')
        ->assertJsonPath('data.reason', 'shrinkage')
        ->assertJsonPath('data.lot_balance_after', '8.000')
        ->assertJsonPath('data.product_balance_after', '8.000');

    expect($this->lot->fresh()->remaining_quantity)->toBe('8.000')
        ->and(AuditLog::withoutTenancy()->where('action', 'inventory.adjustment')->count())->toBe(1);
});

it('HU-4.1 ajuste positivo por conteo', function () {
    ($this->post)("/api/v1/lots/{$this->lot->id}/adjustments", ['quantity' => '3', 'reason' => 'count'])
        ->assertCreated()
        ->assertJsonPath('data.lot_balance_after', '13.000');
});

it('HU-4.3 rechaza un ajuste que deja el lote en negativo', function () {
    ($this->post)("/api/v1/lots/{$this->lot->id}/adjustments", ['quantity' => '-11', 'reason' => 'count'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'El lote quedaría en negativo: su saldo es 10.000.');

    expect($this->lot->fresh()->remaining_quantity)->toBe('10.000');
});

it('valida cantidad distinta de cero, decimales según la unidad y motivo', function () {
    ($this->post)("/api/v1/lots/{$this->lot->id}/adjustments", ['quantity' => '0', 'reason' => 'otro'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'reason']);

    ($this->post)("/api/v1/lots/{$this->lot->id}/adjustments", ['quantity' => '-0.5', 'reason' => 'count'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
});

it('HU-4.2 revertir crea un movimiento inverso enlazado y conserva el original', function () {
    $adjustment = app(InventoryService::class)->adjust($this->lot, '-4', AdjustmentReason::Error, 'Conté mal', $this->admin);

    ($this->post)("/api/v1/movements/{$adjustment->id}/reverse", ['reason' => 'error'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'reversal')
        ->assertJsonPath('data.quantity', '4.000')
        ->assertJsonPath('data.reverses_id', $adjustment->id)
        ->assertJsonPath('data.lot_balance_after', '10.000');

    expect($adjustment->fresh()->quantity)->toBe('-4.000')
        ->and(AuditLog::withoutTenancy()->where('action', 'inventory.reversal')->count())->toBe(1);
});

it('revierte una venta devolviendo el stock a su lote', function () {
    $sale = DB::transaction(fn () => app(InventoryService::class)->consume($this->product, '3', null, $this->admin))->first();

    ($this->post)("/api/v1/movements/{$sale->id}/reverse", ['reason' => 'error'])->assertCreated();

    expect($this->lot->fresh()->remaining_quantity)->toBe('10.000');
});

it('no revierte dos veces el mismo movimiento', function () {
    $adjustment = app(InventoryService::class)->adjust($this->lot, '-1', AdjustmentReason::Damage, null, $this->admin);

    ($this->post)("/api/v1/movements/{$adjustment->id}/reverse", ['reason' => 'error'])->assertCreated();
    ($this->post)("/api/v1/movements/{$adjustment->id}/reverse", ['reason' => 'error'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.movement.0', 'Este movimiento ya fue revertido.');
});

it('no revierte una reversión', function () {
    $adjustment = app(InventoryService::class)->adjust($this->lot, '-1', AdjustmentReason::Damage, null, $this->admin);
    $reversal = app(InventoryService::class)->reverse($adjustment, AdjustmentReason::Error, null, $this->admin);

    ($this->post)("/api/v1/movements/{$reversal->id}/reverse", ['reason' => 'error'])
        ->assertUnprocessable()->assertJsonValidationErrors(['movement']);
});

it('no revierte una entrada cuyo lote ya se consumió', function () {
    DB::transaction(fn () => app(InventoryService::class)->consume($this->product, '7', null, $this->admin));

    ($this->post)("/api/v1/movements/{$this->entry->id}/reverse", ['reason' => 'error'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.movement.0', 'No se puede revertir: el lote ya no tiene el saldo de este movimiento (saldo 3.000).');
});

it('revierte una entrada intacta dejando el lote en cero', function () {
    ($this->post)("/api/v1/movements/{$this->entry->id}/reverse", ['reason' => 'error', 'note' => 'Entrada duplicada'])
        ->assertCreated()
        ->assertJsonPath('data.lot_balance_after', '0.000');

    expect($this->entry->fresh()->type)->toBe(MovementType::Entry);
});

it('HU-4.4 el vendedor no ajusta ni revierte', function () {
    ($this->post)("/api/v1/lots/{$this->lot->id}/adjustments", ['quantity' => '-1', 'reason' => 'count'], $this->seller)->assertForbidden();
    ($this->post)("/api/v1/movements/{$this->entry->id}/reverse", ['reason' => 'error'], $this->seller)->assertForbidden();
});

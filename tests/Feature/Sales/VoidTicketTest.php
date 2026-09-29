<?php

use App\Enums\CompanyRole;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * T040 · HU-3 Anular un ticket (RF-006, A-18).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($this->product)->quantity('3')->create(['received_at' => today()->subDay()]);
    ProductLot::factory()->for($this->product)->quantity('5')->create();

    $this->request = function (string $method, string $uri, array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
    $this->ticketId = ($this->request)('POST', '/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '4']],
    ], $this->seller)->json('data.id');
});

it('HU-3.1 anula con motivo, conserva el número y devuelve el stock', function () {
    ($this->request)('POST', "/api/v1/tickets/{$this->ticketId}/void", ['reason' => 'Cliente se arrepintió'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.display_number', 'T-000001')
        ->assertJsonPath('data.void_reason', 'Cliente se arrepintió');

    $sales = InventoryMovement::withoutTenancy()->where('type', MovementType::Sale)->get();
    $reversals = InventoryMovement::withoutTenancy()->where('type', MovementType::Reversal)->get();

    expect($this->product->fresh()->stock())->toBe('8.000')
        ->and($reversals)->toHaveCount($sales->count())
        ->and($reversals->pluck('reverses_id')->sort()->values()->all())->toBe($sales->pluck('id')->sort()->values()->all())
        ->and(AuditLog::withoutTenancy()->where('action', 'ticket.voided')->count())->toBe(1);
});

it('HU-3.2 el vendedor no anula', function () {
    ($this->request)('POST', "/api/v1/tickets/{$this->ticketId}/void", ['reason' => 'x'], $this->seller)->assertForbidden();
});

it('HU-3.3 no se anula dos veces', function () {
    ($this->request)('POST', "/api/v1/tickets/{$this->ticketId}/void", ['reason' => 'Error'])->assertOk();

    ($this->request)('POST', "/api/v1/tickets/{$this->ticketId}/void", ['reason' => 'Error'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.ticket.0', 'El ticket ya está anulado.');

    expect($this->product->fresh()->stock())->toBe('8.000');
});

it('exige el motivo', function () {
    ($this->request)('POST', "/api/v1/tickets/{$this->ticketId}/void", [])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
});

it('un ticket de servicios se anula sin movimientos', function () {
    $service = Product::factory()->service()->create(['company_id' => $this->company->id]);
    $id = ($this->request)('POST', '/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash',
        'lines' => [['product_id' => $service->id, 'quantity' => '1']],
    ])->json('data.id');

    ($this->request)('POST', "/api/v1/tickets/{$id}/void", ['reason' => 'Error'])->assertOk();
});

it('no permite revertir a mano una venta de un ticket', function () {
    $sale = InventoryMovement::withoutTenancy()->where('type', MovementType::Sale)->first();

    ($this->request)('POST', "/api/v1/movements/{$sale->id}/reverse", ['reason' => 'error'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.movement.0', 'Esta venta pertenece al ticket T-000001: anúlalo para devolver el stock.');
});

<?php

use App\Enums\CompanyRole;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * T030 · HU-2 Entrada de stock por lote (RF-010, RF-011, A-13).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create(['name' => 'Ana']);
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    $this->entry = function (Product $product, array $data, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)
            ->postJson("/api/v1/products/{$product->id}/entries", $data);
    };
});

it('HU-2.1 registra una entrada completa: lote, movimiento y stock', function () {
    ($this->entry)($this->product, [
        'quantity' => '24',
        'received_at' => '2026-09-20',
        'lot_number' => 'F-00123',
        'unit_cost' => '2.3500',
        'reference' => 'Factura F001-123 de Distribuidora Lima',
    ])->assertCreated()
        ->assertJsonPath('data.lot_number', 'F-00123')
        ->assertJsonPath('data.initial_quantity', '24.000')
        ->assertJsonPath('data.remaining_quantity', '24.000')
        ->assertJsonPath('data.unit_cost', '2.3500')
        ->assertJsonPath('data.created_by.name', 'Ana')
        ->assertJsonPath('product_stock', '24.000');

    $movement = InventoryMovement::withoutTenancy()->where('product_id', $this->product->id)->sole();

    expect($movement->type)->toBe(MovementType::Entry)
        ->and($movement->quantity)->toBe('24.000')
        ->and($movement->product_balance_after)->toBe('24.000')
        ->and($movement->created_by)->toBe($this->admin->id)
        ->and(AuditLog::withoutTenancy()->where('action', 'inventory.entry')->count())->toBe(1);
});

it('dos entradas suman stock y crean dos lotes', function () {
    ($this->entry)($this->product, ['quantity' => '10'])->assertCreated();
    ($this->entry)($this->product, ['quantity' => '5'])->assertCreated()->assertJsonPath('product_stock', '15.000');

    expect($this->product->lots()->count())->toBe(2);
});

it('HU-2.2 genera el número de lote y usa la fecha de hoy por defecto', function () {
    $response = ($this->entry)($this->product, ['quantity' => '3'])->assertCreated();

    $lot = ProductLot::withoutTenancy()->findOrFail($response->json('data.id'));

    expect($lot->lot_number)->toBe('L-'.today()->format('Ymd').'-'.$lot->id)
        ->and($lot->received_at->toDateString())->toBe(today()->toDateString());
});

it('HU-2.3 exige vencimiento futuro si el producto lo controla', function () {
    $product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id]);

    ($this->entry)($product, ['quantity' => '5'])
        ->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);

    ($this->entry)($product, ['quantity' => '5', 'expires_at' => today()->subDay()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);

    ($this->entry)($product, ['quantity' => '5', 'expires_at' => today()->addMonth()->toDateString()])
        ->assertCreated();
});

it('no acepta vencimiento si el producto no lo controla', function () {
    ($this->entry)($this->product, ['quantity' => '5', 'expires_at' => today()->addMonth()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);
});

it('HU-2.4 un servicio no admite entradas', function () {
    $service = Product::factory()->service()->create(['company_id' => $this->company->id]);

    ($this->entry)($service, ['quantity' => '5'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.product.0', 'Los servicios no tienen stock.');
});

it('HU-2.5 rechaza cantidades cero, negativas o con decimales en unidades enteras', function (string $quantity) {
    ($this->entry)($this->product, ['quantity' => $quantity])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
})->with(['0', '-3', '2.5']);

it('acepta decimales en productos por peso', function () {
    $kilo = Product::factory()->byWeight()->create(['company_id' => $this->company->id]);

    ($this->entry)($kilo, ['quantity' => '12.750'])->assertCreated()->assertJsonPath('product_stock', '12.750');
});

it('rechaza una fecha de ingreso futura y un número de lote repetido', function () {
    ProductLot::factory()->for($this->product)->create(['lot_number' => 'F-1']);

    ($this->entry)($this->product, ['quantity' => '1', 'lot_number' => 'F-1', 'received_at' => today()->addDay()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['lot_number', 'received_at']);
});

it('el vendedor no registra entradas', function () {
    ($this->entry)($this->product, ['quantity' => '5'], $this->seller)->assertForbidden();
});

it('lista los lotes con saldo; el vendedor no ve el costo', function () {
    ProductLot::factory()->for($this->product)->quantity('4')->create(['unit_cost' => '1.5000', 'lot_number' => 'A']);
    $vacio = ProductLot::factory()->for($this->product)->quantity('2')->create(['lot_number' => 'VACIO']);
    DB::table('product_lots')->where('id', $vacio->id)->update(['remaining_quantity' => 0]);

    app('auth')->forgetGuards();
    $asSeller = $this->withToken($this->seller->createToken('t')->plainTextToken)
        ->getJson("/api/v1/products/{$this->product->id}/lots")->assertOk();

    expect(collect($asSeller->json('data'))->pluck('lot_number')->all())->toBe(['A'])
        ->and($asSeller->json('data.0'))->not->toHaveKey('unit_cost');

    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->getJson("/api/v1/products/{$this->product->id}/lots?include_empty=1")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.unit_cost', '1.5000');
});

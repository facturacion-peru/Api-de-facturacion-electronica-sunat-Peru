<?php

use App\Enums\MovementType;
use App\Inventory\Exceptions\MovementImmutable;
use App\Models\InventoryMovement;
use App\Models\ProductLot;
use Illuminate\Database\QueryException;

/*
 * T013 · Los movimientos de inventario no se editan ni se borran (RF-011,
 * RF-016): los errores se corrigen con una reversión.
 */

beforeEach(function () {
    $this->lot = ProductLot::factory()->quantity('10')->create();
    $this->movement = InventoryMovement::withoutTenancy()->where('lot_id', $this->lot->id)->sole();
});

it('impide modificar un movimiento', function () {
    $this->movement->update(['quantity' => '99']);
})->throws(MovementImmutable::class);

it('impide borrar un movimiento', function () {
    $this->movement->delete();
})->throws(MovementImmutable::class);

it('un movimiento solo puede revertirse una vez', function () {
    $reversal = fn () => InventoryMovement::create([
        'company_id' => $this->lot->company_id,
        'product_id' => $this->lot->product_id,
        'lot_id' => $this->lot->id,
        'type' => MovementType::Reversal,
        'quantity' => '-10',
        'lot_balance_after' => '0',
        'product_balance_after' => '0',
        'reverses_id' => $this->movement->id,
    ]);

    $reversal();
    $reversal();
})->throws(QueryException::class);

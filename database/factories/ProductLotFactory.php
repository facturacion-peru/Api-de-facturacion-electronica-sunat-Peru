<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crea el lote junto con su movimiento de entrada, para que los datos de
 * prueba cumplan siempre saldo = suma de movimientos (RF-013).
 *
 * @extends Factory<ProductLot>
 */
class ProductLotFactory extends Factory
{
    public function definition(): array
    {
        $quantity = (string) fake()->numberBetween(1, 50);

        return [
            'product_id' => Product::factory(),
            'company_id' => fn (array $attributes) => Product::withoutTenancy()->find($attributes['product_id'])->company_id,
            'lot_number' => fake()->unique()->bothify('LOTE-####'),
            'received_at' => today(),
            'expires_at' => null,
            'initial_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => null,
        ];
    }

    public function expiringOn(string $date): static
    {
        return $this->state(fn () => ['expires_at' => $date]);
    }

    public function quantity(string $quantity): static
    {
        return $this->state(fn () => ['initial_quantity' => $quantity, 'remaining_quantity' => $quantity]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ProductLot $lot) {
            $productBalance = ProductLot::withoutTenancy()->where('product_id', $lot->product_id)->sum('remaining_quantity');

            InventoryMovement::create([
                'company_id' => $lot->company_id,
                'product_id' => $lot->product_id,
                'lot_id' => $lot->id,
                'type' => MovementType::Entry,
                'quantity' => $lot->initial_quantity,
                'lot_balance_after' => $lot->remaining_quantity,
                'product_balance_after' => bcadd((string) $productBalance, '0', 3),
            ]);
        });
    }
}

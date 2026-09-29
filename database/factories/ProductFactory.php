<?php

namespace Database\Factories;

use App\Enums\IgvAffectation;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->bothify('P-####??'),
            'name' => ucfirst(fake()->words(2, true)),
            'type' => ProductType::Good,
            'unit' => UnitOfMeasure::Unit,
            'sale_price' => fake()->randomFloat(2, 1, 500),
            'igv_affectation' => IgvAffectation::Gravado,
            'min_stock' => null,
            'tracks_expiry' => false,
            'active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => ['type' => ProductType::Service, 'unit' => UnitOfMeasure::Service]);
    }

    public function tracksExpiry(): static
    {
        return $this->state(fn () => ['tracks_expiry' => true]);
    }

    public function byWeight(): static
    {
        return $this->state(fn () => ['unit' => UnitOfMeasure::Kilogram]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}

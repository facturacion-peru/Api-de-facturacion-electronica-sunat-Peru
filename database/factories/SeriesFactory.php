<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Series>
 */
class SeriesFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory()->withMainEstablishment(),
            'establishment_id' => fn (array $a) => Establishment::withoutTenancy()->where('company_id', $a['company_id'])->where('is_main', true)->value('id'),
            'document_type' => DocumentType::Receipt,
            'code' => 'B'.fake()->unique()->numerify('###'),
            'last_number' => 0,
            'active' => true,
        ];
    }

    public function invoice(): static
    {
        return $this->state(fn () => ['document_type' => DocumentType::Invoice, 'code' => 'F'.fake()->unique()->numerify('###')]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Enums\TaxRegime;
use App\Models\Company;
use App\Models\Establishment;
use App\Rules\Ruc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ruc' => self::validRuc('20'),
            'razon_social' => mb_strtoupper(fake()->company()).' S.A.C.',
            'nombre_comercial' => fake()->optional()->company(),
            'person_type' => PersonType::Juridica,
            'tax_regime' => TaxRegime::Rmt,
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->optional()->numerify('9########'),
            'active' => true,
        ];
    }

    public function naturalPerson(): static
    {
        return $this->state(fn () => [
            'ruc' => self::validRuc('10'),
            'person_type' => PersonType::Natural,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** Crea también el establecimiento principal, como hace el alta real. */
    public function withMainEstablishment(): static
    {
        return $this->afterCreating(function (Company $company) {
            Establishment::factory()->main()->for($company)->create();
        });
    }

    /** RUC aleatorio con dígito verificador válido (módulo 11). */
    public static function validRuc(string $prefix): string
    {
        do {
            $base = $prefix.fake()->numerify('########');
        } while (Company::where('ruc', 'like', $base.'%')->exists());

        return $base.Ruc::checkDigit($base);
    }
}

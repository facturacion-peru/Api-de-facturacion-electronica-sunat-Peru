<?php

namespace Database\Factories;

use App\Enums\CustomerDocumentType;
use App\Models\Company;
use App\Models\Customer;
use App\Rules\Ruc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_type' => CustomerDocumentType::Dni,
            'document_number' => fake()->unique()->numerify('4#######'),
            'name' => mb_strtoupper(fake()->name()),
            'address' => null,
        ];
    }

    /** Empresa cliente con RUC válido (dígito verificador correcto). */
    public function withRuc(): static
    {
        return $this->state(function () {
            $base = '20'.fake()->unique()->numerify('########');

            return [
                'document_type' => CustomerDocumentType::Ruc,
                'document_number' => $base.Ruc::checkDigit($base),
                'name' => mb_strtoupper(fake()->company()).' S.A.C.',
                'address' => 'AV. CLIENTE 456, LIMA',
            ];
        });
    }
}

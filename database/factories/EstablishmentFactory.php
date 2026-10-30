<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Establishment>
 */
class EstablishmentFactory extends Factory
{
    /** Lima / Lima / Lima: se crea al vuelo en pruebas sin seeders de ubigeo. */
    public const LIMA = '150101';

    public function definition(): array
    {
        self::ensureLimaUbigeo();

        return [
            'company_id' => Company::factory(),
            // Nunca 0000: ese código es del establecimiento principal.
            'code' => fake()->unique()->numerify('1###'),
            'name' => 'Local '.fake()->citySuffix(),
            'address' => fake()->streetAddress(),
            'ubigeo' => self::LIMA,
            'is_main' => false,
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => [
            'code' => Establishment::MAIN_CODE,
            'name' => 'Domicilio fiscal',
            'is_main' => true,
        ]);
    }

    /**
     * Id del principal de la empresa, creándolo si la prueba no lo hizo: las
     * factories de lotes, tickets y comprobantes lo usan por defecto (spec 017).
     */
    public static function mainIdFor(int $companyId): int
    {
        return Establishment::withoutTenancy()->where('company_id', $companyId)->where('is_main', true)->value('id')
            ?? Establishment::factory()->main()->create(['company_id' => $companyId])->id;
    }

    public static function ensureLimaUbigeo(): void
    {
        DB::table('ubi_regiones')->insertOrIgnore(['id' => '150000', 'nombre' => 'Lima']);
        DB::table('ubi_provincias')->insertOrIgnore(['id' => '150100', 'nombre' => 'Lima', 'region_id' => '150000']);
        DB::table('ubi_distritos')->insertOrIgnore([
            'id' => self::LIMA,
            'nombre' => 'Lima',
            'info_busqueda' => 'Lima / Lima / Lima',
            'provincia_id' => '150100',
            'region_id' => '150000',
        ]);
    }
}

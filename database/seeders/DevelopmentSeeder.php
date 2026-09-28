<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Enums\PersonType;
use App\Enums\TaxRegime;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Establishment;
use App\Models\User;
use App\Rules\Ruc;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Seeder;

/**
 * Cuentas de prueba para desarrollo. Solo en APP_ENV=local: permite hacer
 * `php artisan migrate:fresh --seed` sin perder las cuentas de trabajo.
 *
 * Todas usan la misma contraseña (PASSWORD) y el dominio reservado .test.
 * Es idempotente: se puede ejecutar varias veces.
 */
class DevelopmentSeeder extends Seeder
{
    public const PASSWORD = 'Clave-demo-123';

    public const PLATFORM_ADMIN = 'plataforma@demo.test';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DevelopmentSeeder: omitido (APP_ENV no es local).');

            return;
        }

        EstablishmentFactory::ensureLimaUbigeo();

        $this->user('Administrador Plataforma', self::PLATFORM_ADMIN, platformAdmin: true);

        $demo = $this->company('2060000001', 'Empresa Demo S.A.C.', 'Bodega Demo', 'contacto@demo.test');
        $this->member($demo, 'Ana Administradora', 'admin@demo.test', CompanyRole::CompanyAdmin);
        $this->member($demo, 'Luis Vendedor', 'vendedor@demo.test', CompanyRole::Seller);
        $this->member($demo, 'Carla Desactivada', 'inactivo@demo.test', CompanyRole::Seller, active: false);

        // Segunda empresa para comprobar el aislamiento entre empresas.
        $otra = $this->company('2060000002', 'Otra Empresa S.A.C.', 'Otra Tienda', 'contacto@otra.test');
        $this->member($otra, 'Olga Administradora', 'admin@otra.test', CompanyRole::CompanyAdmin);

        $this->command?->info('Usuarios de prueba listos (contraseña: '.self::PASSWORD.').');
    }

    private function user(string $name, string $email, bool $platformAdmin = false): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->fill(['name' => $name, 'password' => self::PASSWORD, 'active' => true]);
        $user->is_platform_admin = $platformAdmin;
        $user->save();

        return $user;
    }

    /** RUC ficticio con dígito verificador válido a partir de 10 dígitos. */
    private function company(string $base, string $razonSocial, string $nombreComercial, string $email): Company
    {
        $ruc = $base.Ruc::checkDigit($base);

        $company = Company::updateOrCreate(['ruc' => $ruc], [
            'razon_social' => $razonSocial,
            'nombre_comercial' => $nombreComercial,
            'person_type' => PersonType::Juridica,
            'tax_regime' => TaxRegime::Rmt,
            'email' => $email,
            'active' => true,
        ]);

        Establishment::withoutTenancy()->updateOrCreate(
            ['company_id' => $company->id, 'code' => Establishment::MAIN_CODE],
            ['name' => 'Domicilio fiscal', 'address' => 'Av. Demo 123', 'ubigeo' => EstablishmentFactory::LIMA, 'is_main' => true],
        );

        return $company;
    }

    private function member(Company $company, string $name, string $email, CompanyRole $role, bool $active = true): void
    {
        $user = $this->user($name, $email);

        CompanyMembership::withoutTenancy()->updateOrCreate(
            ['user_id' => $user->id],
            ['company_id' => $company->id, 'role' => $role, 'active' => $active],
        );
    }
}

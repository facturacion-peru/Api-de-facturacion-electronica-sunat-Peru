<?php

use App\Enums\CompanyRole;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\SunatSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
 * Usuarios de prueba solo en APP_ENV=local, para poder hacer
 * migrate:fresh --seed sin perder las cuentas de desarrollo.
 */

function asEnvironment(string $env): void
{
    app()->detectEnvironment(fn () => $env);
}

// El seeder intenta validar la configuración demo contra SUNAT beta: en
// pruebas se simula, para no depender de la red.
// Lo mismo con el envío de los comprobantes demo (spec 005).
beforeEach(function () {
    Http::fake(['*' => Http::response('<wsdl:definitions/>', 200)]);
    app()->instance(SunatSender::class, new FakeSunatSender);
});

/** --force: en production db:seed pide confirmación interactiva. */
function seedForced(string $class): void
{
    test()->artisan('db:seed', ['--class' => $class, '--force' => true])->assertSuccessful();
}

it('en local crea la plataforma, las empresas demo y sus usuarios', function () {
    asEnvironment('local');

    $this->seed(DatabaseSeeder::class);

    $roles = CompanyMembership::withoutTenancy()->with('user')->get()
        ->mapWithKeys(fn ($m) => [$m->user->email => [$m->role, $m->active]]);

    expect(User::where('email', DevelopmentSeeder::PLATFORM_ADMIN)->value('is_platform_admin'))->toBeTrue()
        ->and(Company::count())->toBe(2)
        ->and($roles['admin@demo.test'])->toBe([CompanyRole::CompanyAdmin, true])
        ->and($roles['vendedor@demo.test'])->toBe([CompanyRole::Seller, true])
        ->and($roles['inactivo@demo.test'])->toBe([CompanyRole::Seller, false])
        ->and($roles['admin@otra.test'])->toBe([CompanyRole::CompanyAdmin, true])
        ->and(Hash::check(DevelopmentSeeder::PASSWORD, User::where('email', 'admin@demo.test')->value('password')))->toBeTrue();

    $azucar = Product::withoutTenancy()->where('code', 'AZU-001')->first();
    expect(app(TenantContext::class)->run($azucar->company, fn () => $azucar->stock()))->toBe('48.000'); // 50.500 − 2.500 vendidos en la venta demo

    Company::with('mainEstablishment')->get()->each(
        fn (Company $company) => expect($company->mainEstablishment?->code)->toBe('0000')
    );
});

it('se puede ejecutar dos veces sin duplicar nada', function () {
    asEnvironment('local');

    $this->seed(DevelopmentSeeder::class);
    $this->seed(DevelopmentSeeder::class);

    expect(User::count())->toBe(5)
        ->and(Company::count())->toBe(2)
        ->and(Product::withoutTenancy()->count())->toBe(47) // 6 + 41 del surtido de bodega (spec 012)
        ->and(ProductLot::withoutTenancy()->count())->toBe(45) // 7 + 38 (sin lote: 2 servicios y la mostaza sin stock)
        ->and(Ticket::withoutTenancy()->count())->toBe(4)
        ->and(Ticket::withoutTenancy()->where('status', 'voided')->count())->toBe(1)
        ->and(Certificate::withoutTenancy()->count())->toBe(1)
        ->and(Series::withoutTenancy()->orderBy('code')->pluck('code')->all())->toBe(['B001', 'BC01', 'F001', 'FC01'])
        ->and(SunatSetting::withoutTenancy()->value('status')->value)->toBe('validated') // SUNAT simulado responde
        ->and(Customer::withoutTenancy()->count())->toBe(2)
        ->and(SalesDocument::withoutTenancy()->orderBy('series_code')->get()->map(fn ($d) => $d->display_number.' '.$d->status->value)->all())
        ->toBe(['B001-00000001 accepted', 'F001-00000001 accepted']);
});

it('fuera de local no crea usuarios ni empresas', function (string $env) {
    asEnvironment($env);

    seedForced(DatabaseSeeder::class);

    expect(User::count())->toBe(0)->and(Company::count())->toBe(0)->and(Product::withoutTenancy()->count())->toBe(0);
})->with(['production', 'staging', 'testing']);

it('DevelopmentSeeder se niega a correr fuera de local aunque se llame directo', function () {
    asEnvironment('production');

    seedForced(DevelopmentSeeder::class);

    expect(User::count())->toBe(0);
});

<?php

use App\Enums\CompanyRole;
use App\Enums\SunatStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SunatSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestCertificates;

/*
 * T030 · HU-2 Validar la configuración (RF-010 a RF-015, A-31).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create(['ruc' => '20131312955']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->call = function (string $method, string $uri, array $data = []) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
    $this->configure = function (int $days = 365) {
        ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'ventas01', 'sol_password' => 'Clave-Sol-9']);
        app('auth')->forgetGuards();
        $this->withToken($this->admin->createToken('t')->plainTextToken)->post('/api/v1/sunat/certificate', [
            'certificate' => UploadedFile::fake()->createWithContent('c.pfx', TestCertificates::make(days: $days)['pfx']),
            'password' => 'clave-cert-123',
        ], ['Accept' => 'application/json'])->assertOk();
    };
    $this->sunatUp = fn () => Http::fake(['*' => Http::response('<wsdl:definitions name="billService"></wsdl:definitions>', 200)]);
});

afterEach(fn () => Carbon::setTestNow());

it('HU-2.1 con certificado válido y SUNAT beta respondiendo queda validada', function () {
    ($this->sunatUp)();
    ($this->configure)();

    ($this->call)('POST', '/api/v1/sunat/validate')
        ->assertOk()
        ->assertJsonPath('data.status', 'validated')
        ->assertJsonPath('data.last_validation_error', null);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'e-beta.sunat.gob.pe'));
    expect(SunatSetting::withoutTenancy()->first()->last_validated_at)->not->toBeNull()
        ->and(AuditLog::withoutTenancy()->where('action', 'sunat.validated')->count())->toBe(1);
});

it('HU-2.5 y A-31 en beta la clave SOL real queda como no verificada', function () {
    ($this->sunatUp)();
    ($this->configure)();

    ($this->call)('POST', '/api/v1/sunat/validate')->assertJsonPath('data.sol_verified', false);
});

it('HU-2.2 una configuración incompleta lista lo que falta sin cambiar el estado', function () {
    ($this->sunatUp)();
    ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'ventas01', 'sol_password' => 'Clave-Sol-9']);

    ($this->call)('POST', '/api/v1/sunat/validate')
        ->assertUnprocessable()
        ->assertJsonPath('errors.configuration', ['Sube el certificado digital.']);

    ($this->call)('GET', '/api/v1/sunat/settings')->assertJsonPath('data.status', 'pending');
    Http::assertNothingSent();
});

it('HU-2.3 si SUNAT no responde informa sin marcar la configuración como errónea', function () {
    Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
    ($this->configure)();

    ($this->call)('POST', '/api/v1/sunat/validate')
        ->assertStatus(503)
        ->assertJsonPath('message', 'SUNAT no responde en este momento. Inténtalo más tarde.');

    expect(SunatSetting::withoutTenancy()->first()->status)->toBe(SunatStatus::Pending);
});

it('un error de SUNAT (5xx) también cuenta como no disponible', function () {
    Http::fake(['*' => Http::response('Service Unavailable', 503)]);
    ($this->configure)();

    ($this->call)('POST', '/api/v1/sunat/validate')->assertStatus(503);
});

it('HU-2.4 cambiar la clave o el certificado vuelve a pendiente', function () {
    ($this->sunatUp)();
    ($this->configure)();
    ($this->call)('POST', '/api/v1/sunat/validate')->assertJsonPath('data.status', 'validated');

    ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'ventas01', 'sol_password' => 'Nueva-Clave-1'])
        ->assertJsonPath('data.status', 'pending');
});

it('un certificado que vence después de validar deja la configuración con error', function () {
    ($this->sunatUp)();
    ($this->configure)(days: 2);
    ($this->call)('POST', '/api/v1/sunat/validate')->assertJsonPath('data.status', 'validated');

    Carbon::setTestNow(now()->addDays(5));

    ($this->call)('GET', '/api/v1/sunat/settings')
        ->assertJsonPath('data.status', 'error')
        ->assertJsonPath('data.last_validation_error', 'El certificado está vencido. Sube uno vigente.');
});

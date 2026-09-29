<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SunatSetting;
use App\Models\User;

/*
 * T022 · HU-1.1, HU-1.4 y HU-1.5: credenciales SOL, protegidas y visibles
 * solo como metadatos.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create(['ruc' => '20131312955']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->call = function (string $method, string $uri, array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
});

it('sin configurar muestra el estado «no configurada»', function () {
    ($this->call)('GET', '/api/v1/sunat/settings')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_configured')
        ->assertJsonPath('data.environment', 'beta')
        ->assertJsonPath('data.has_sol_password', false)
        ->assertJsonPath('data.certificate', null);
});

it('HU-1.1 guarda las credenciales SOL y deja la configuración pendiente', function () {
    ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'ventas01', 'sol_password' => 'Clave-Sol-Secreta-9'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.sol_user_masked', 'VE****01')
        ->assertJsonPath('data.has_sol_password', true)
        ->assertJsonPath('data.sol_verified', false);

    expect(SunatSetting::withoutTenancy()->first()->sol_password)->toBe('Clave-Sol-Secreta-9')
        ->and(SunatSetting::withoutTenancy()->first()->sol_user)->toBe('VENTAS01');
});

it('HU-1.4 la respuesta nunca incluye la clave SOL', function () {
    $response = ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'ventas01', 'sol_password' => 'Clave-Sol-Secreta-9']);
    $settings = ($this->call)('GET', '/api/v1/sunat/settings');

    expect($response->getContent().$settings->getContent())->not->toContain('Clave-Sol-Secreta-9')->not->toContain('VENTAS01');
});

it('audita el cambio de credenciales sin guardar la clave', function () {
    ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'ventas01', 'sol_password' => 'Clave-Sol-Secreta-9']);

    $log = AuditLog::withoutTenancy()->where('action', 'sunat.credentials_updated')->sole();
    expect(json_encode($log->changes))->not->toContain('Clave-Sol-Secreta-9');
});

it('valida usuario y clave', function () {
    ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'con espacios!', 'sol_password' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['sol_user', 'sol_password']);
});

it('HU-1.5 el vendedor no ve ni cambia la configuración', function () {
    ($this->call)('GET', '/api/v1/sunat/settings', [], $this->seller)->assertForbidden();
    ($this->call)('PUT', '/api/v1/sunat/credentials', ['sol_user' => 'X1', 'sol_password' => 'Y'], $this->seller)->assertForbidden();
});

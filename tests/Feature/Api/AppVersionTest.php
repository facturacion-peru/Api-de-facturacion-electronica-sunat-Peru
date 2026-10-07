<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

/*
 * Spec 013 · T006, A-67: la app Android envía su versión en X-App-Version y
 * la API rechaza con 426 las versiones por debajo de la mínima. La web no
 * envía la cabecera y nunca se ve afectada.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->user = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->token = $this->user->createToken('t')->plainTextToken;
    config(['app.android_min_version' => '0.3.0']);
});

function asApp(?string $version)
{
    return test()->withToken(test()->token)
        ->withHeaders($version === null ? [] : ['X-App-Version' => $version])
        ->getJson('/api/v1/auth/me');
}

it('sin la cabecera (web) no hay control de versión', function () {
    asApp(null)->assertOk();
});

it('una versión menor que la mínima recibe 426 con la versión mínima', function (string $version) {
    asApp($version)->assertStatus(426)
        ->assertJsonPath('success', false)
        ->assertJsonPath('min_version', '0.3.0')
        ->assertJsonPath('message', 'Esta versión de la app ya no es compatible. Actualízala para seguir usándola.');
})->with(['0.2.9', '0.1.0', 'no-es-version']);

it('la versión mínima o una mayor pasan', function (string $version) {
    asApp($version)->assertOk();
})->with(['0.3.0', '0.3.1', '1.0.0']);

it('también aplica a rutas públicas como el inicio de sesión', function () {
    $this->withHeaders(['X-App-Version' => '0.1.0'])
        ->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'x'])
        ->assertStatus(426);
});

it('sin versión mínima configurada no hay control', function () {
    config(['app.android_min_version' => null]);

    asApp('0.0.1')->assertOk();
});

it('CORS admite la cabecera X-App-Version', function () {
    $response = $this->call('OPTIONS', '/api/v1/auth/me', server: [
        'HTTP_ORIGIN' => config('app.frontend_url'),
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-app-version',
    ]);

    expect(strtolower((string) $response->headers->get('Access-Control-Allow-Headers')))->toContain('x-app-version');
});

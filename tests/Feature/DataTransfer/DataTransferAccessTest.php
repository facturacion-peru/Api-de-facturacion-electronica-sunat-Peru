<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

/*
 * Spec 014 · RF-001, CE-005 (T008): solo el administrador de empresa exporta e
 * importa. El aislamiento entre empresas lo cubre CrossTenantAccessTest con
 * las rutas de TenantRoutes.php.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->platformAdmin = User::factory()->platformAdmin()->create();
});

dataset('data transfer routes', [
    'exportar productos' => ['GET', '/api/v1/exports/products'],
    'exportar clientes' => ['GET', '/api/v1/exports/customers'],
    'exportar ventas' => ['GET', '/api/v1/exports/sales?from=2026-10-01&to=2026-10-07'],
]);

it('el vendedor no puede exportar ni importar', function (string $method, string $uri) {
    $this->withToken($this->seller->createToken('t')->plainTextToken)->json($method, $uri)->assertForbidden();
})->with('data transfer routes');

it('el administrador de la plataforma no exporta datos de las empresas (A-37)', function (string $method, string $uri) {
    $response = $this->withToken($this->platformAdmin->createToken('t')->plainTextToken)->json($method, $uri);

    expect($response->status())->toBeIn([401, 403]);
})->with('data transfer routes');

it('sin sesión responde 401', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with('data transfer routes');

<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * T017 · Roles fijos (RF-020/021): role:company_admin y platform.admin.
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'role:company_admin'])
        ->get('api/_pruebas/solo-admin', fn () => 'ok');

    Route::middleware(['api', 'auth:sanctum', 'platform.admin'])
        ->get('api/_pruebas/plataforma', fn () => 'ok');

    $this->company = Company::factory()->withMainEstablishment()->create();
});

it('permite la ruta de administrador al administrador de empresa', function () {
    $admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();

    $this->withToken($admin->createToken('t')->plainTextToken)
        ->getJson('/api/_pruebas/solo-admin')->assertOk();
});

it('rechaza al vendedor en una ruta de administrador', function () {
    $seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();

    $this->withToken($seller->createToken('t')->plainTextToken)
        ->getJson('/api/_pruebas/solo-admin')->assertForbidden();
});

it('permite la ruta de plataforma al administrador de la plataforma', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->withToken($admin->createToken('t')->plainTextToken)
        ->getJson('/api/_pruebas/plataforma')->assertOk();
});

it('rechaza a un usuario de empresa en una ruta de plataforma', function () {
    $user = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/_pruebas/plataforma')->assertForbidden();
});

it('rechaza con 401 y revoca el token de un administrador de plataforma desactivado', function () {
    $admin = User::factory()->platformAdmin()->inactive()->create();

    $this->withToken($admin->createToken('t')->plainTextToken)
        ->getJson('/api/_pruebas/plataforma')->assertUnauthorized();

    expect($admin->tokens()->count())->toBe(0);
});

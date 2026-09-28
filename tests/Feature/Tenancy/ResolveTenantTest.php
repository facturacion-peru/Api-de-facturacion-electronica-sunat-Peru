<?php

use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

/*
 * T017 · ResolveTenant fija la empresa del usuario y corta el acceso de
 * cuentas o empresas inactivas (RF-013, CE-004).
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant'])->prefix('api/_pruebas')->group(function () {
        Route::get('/empresa', fn () => ['company_id' => app(TenantContext::class)->id()]);
    });

    $this->company = Company::factory()->withMainEstablishment()->create();
});

function tokenFor(User $user): string
{
    return $user->createToken('pruebas')->plainTextToken;
}

it('fija el contexto con la empresa del usuario', function () {
    $user = User::factory()->forCompany($this->company)->create();

    $this->withToken(tokenFor($user))->getJson('/api/_pruebas/empresa')
        ->assertOk()
        ->assertJson(['company_id' => $this->company->id]);
});

it('rechaza con 401 y revoca el token de un usuario desactivado', function () {
    $user = User::factory()->inactive()->forCompany($this->company)->create();

    $this->withToken(tokenFor($user))->getJson('/api/_pruebas/empresa')->assertUnauthorized();

    expect($user->tokens()->count())->toBe(0);
});

it('rechaza con 401 y revoca el token si la pertenencia está inactiva', function () {
    $user = User::factory()->forCompany($this->company, active: false)->create();

    $this->withToken(tokenFor($user))->getJson('/api/_pruebas/empresa')->assertUnauthorized();

    expect($user->tokens()->count())->toBe(0);
});

it('rechaza con 401 y revoca el token si la empresa está desactivada', function () {
    $user = User::factory()->forCompany($this->company)->create();
    $this->company->update(['active' => false]);

    $this->withToken(tokenFor($user))->getJson('/api/_pruebas/empresa')->assertUnauthorized();

    expect($user->tokens()->count())->toBe(0);
});

it('impide al administrador de la plataforma entrar en rutas de empresa', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->withToken(tokenFor($admin))->getJson('/api/_pruebas/empresa')->assertForbidden();
});

it('impide el acceso a un usuario sin empresa', function () {
    $user = User::factory()->create();

    $this->withToken(tokenFor($user))->getJson('/api/_pruebas/empresa')->assertForbidden();
});

<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;

/*
 * T052 · HU-3.2 y HU-3.3: listar usuarios, cambiar su rol y desactivarlos,
 * sin dejar nunca la empresa sin administrador activo (RF-015).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create(['name' => 'Ana']);
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create(['name' => 'Luis']);
    $this->asAdmin = fn () => $this->withToken($this->admin->createToken('t')->plainTextToken);
});

function auditCount(string $action): int
{
    return AuditLog::withoutTenancy()->where('action', $action)->count();
}

it('lista solo los usuarios de su empresa', function () {
    User::factory()->forCompany(Company::factory()->create())->create(['name' => 'Ajeno']);
    User::factory()->platformAdmin()->create(['name' => 'Plataforma']);

    $response = ($this->asAdmin)()->getJson('/api/v1/users')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->sort()->values()->all())->toBe(['Ana', 'Luis'])
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'email', 'role', 'active', 'last_login_at']);
});

it('cambia el rol de un usuario y lo audita', function () {
    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->seller->id}", ['role' => 'company_admin'])
        ->assertOk()
        ->assertJsonPath('data.role', 'company_admin');

    expect($this->seller->membership()->first()->role)->toBe(CompanyRole::CompanyAdmin)
        ->and(auditCount('user.role_changed'))->toBe(1);
});

it('desactiva a un usuario, revoca sus sesiones y lo audita', function () {
    $this->seller->createToken('celular');

    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->seller->id}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false);

    expect($this->seller->membership()->first()->active)->toBeFalse()
        ->and($this->seller->tokens()->count())->toBe(0)
        ->and(auditCount('user.deactivated'))->toBe(1);
});

it('reactiva a un usuario', function () {
    $this->seller->membership()->first()->update(['active' => false]);

    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->seller->id}", ['active' => true])
        ->assertOk()
        ->assertJsonPath('data.active', true);

    expect(auditCount('user.activated'))->toBe(1);
});

it('HU-3.3 el único administrador no puede desactivarse', function () {
    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->admin->id}", ['active' => false])
        ->assertUnprocessable()
        ->assertJsonPath('errors.user.0', 'La empresa debe tener al menos un administrador activo.');

    expect($this->admin->membership()->first()->active)->toBeTrue();
});

it('HU-3.3 el único administrador no puede quitarse el rol', function () {
    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'seller'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user']);
});

it('con otro administrador activo sí puede dejar de serlo', function () {
    User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();

    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'seller'])->assertOk();
});

it('un administrador desactivado no cuenta como administrador activo', function () {
    User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin, active: false)->create();

    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->admin->id}", ['active' => false])->assertUnprocessable();
});

it('valida el rol', function () {
    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->seller->id}", ['role' => 'jefe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('responde 404 a un usuario de otra empresa', function () {
    $ajeno = User::factory()->forCompany(Company::factory()->create())->create();

    ($this->asAdmin)()->patchJson("/api/v1/users/{$ajeno->id}", ['active' => false])->assertNotFound();

    expect($ajeno->membership()->first()->active)->toBeTrue();
});

it('responde 404 a un administrador de la plataforma', function () {
    $root = User::factory()->platformAdmin()->create();

    ($this->asAdmin)()->patchJson("/api/v1/users/{$root->id}", ['active' => false])->assertNotFound();
});

it('HU-3.2 el vendedor no gestiona usuarios', function () {
    $asSeller = $this->withToken($this->seller->createToken('t')->plainTextToken);

    $asSeller->getJson('/api/v1/users')->assertForbidden();
    $asSeller->patchJson("/api/v1/users/{$this->admin->id}", ['active' => false])->assertForbidden();
});

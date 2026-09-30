<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;

/*
 * T070 · HU-7 y HU-4.4: el administrador de la plataforma lista, corrige,
 * desactiva y reactiva empresas, sin ver nunca sus secretos.
 */

beforeEach(function () {
    $this->root = User::factory()->platformAdmin()->create();
    $this->asRoot = fn () => $this->withToken($this->root->createToken('t')->plainTextToken);
    $this->company = Company::factory()->withMainEstablishment()->create(['ruc' => '20131312955']);
});

it('lista todas las empresas con su estado, paginadas', function () {
    Company::factory()->count(2)->inactive()->withMainEstablishment()->create();

    $response = ($this->asRoot)()->getJson('/api/v1/platform/companies')->assertOk();

    expect($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page', 'from', 'to'])
        ->and(collect($response->json('data'))->pluck('active')->filter()->count())->toBe(1);
});

it('HU-4.4 nunca expone secretos', function () {
    $body = ($this->asRoot)()->getJson("/api/v1/platform/companies/{$this->company->id}")->assertOk()->getContent();

    expect(mb_strtolower($body))->not->toContain('clave')
        ->not->toContain('password')
        ->not->toContain('certificado')
        ->not->toContain('secret');
});

it('corrige el RUC y recalcula el tipo de persona', function () {
    ($this->asRoot)()->patchJson("/api/v1/platform/companies/{$this->company->id}", ['ruc' => '10468536248'])
        ->assertOk()
        ->assertJsonPath('data.ruc', '10468536248')
        ->assertJsonPath('data.person_type', 'natural');

    expect(AuditLog::withoutTenancy()->where('action', 'company.updated')->where('company_id', $this->company->id)->count())->toBe(1);
});

it('valida el RUC al corregirlo', function () {
    Company::factory()->create(['ruc' => '20100070970']);

    ($this->asRoot)()->patchJson("/api/v1/platform/companies/{$this->company->id}", ['ruc' => '20131312956'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ruc']);

    ($this->asRoot)()->patchJson("/api/v1/platform/companies/{$this->company->id}", ['ruc' => '20100070970'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ruc']);
});

it('HU-7.1 al desactivar corta el acceso de sus usuarios y conserva los datos', function () {
    $user = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $user->createToken('celular');

    ($this->asRoot)()->postJson("/api/v1/platform/companies/{$this->company->id}/deactivate", ['reason' => 'Falta de pago del servicio'])
        ->assertOk()
        ->assertJsonPath('data.active', false);

    expect($user->tokens()->count())->toBe(0)
        ->and(User::find($user->id))->not->toBeNull()
        ->and(AuditLog::withoutTenancy()->where('action', 'company.deactivated')->sole()->changes)->toBe(['reason' => 'Falta de pago del servicio']);
});

it('006 HU-4.1 suspender exige un motivo', function () {
    ($this->asRoot)()->postJson("/api/v1/platform/companies/{$this->company->id}/deactivate", ['reason' => ''])
        ->assertStatus(422)->assertJsonValidationErrors(['reason']);

    expect($this->company->fresh()->active)->toBeTrue();
});

it('HU-7.2 reactiva una empresa', function () {
    $this->company->update(['active' => false]);

    ($this->asRoot)()->postJson("/api/v1/platform/companies/{$this->company->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.active', true);

    expect(AuditLog::withoutTenancy()->where('action', 'company.activated')->count())->toBe(1);
});

it('rechaza a los usuarios de empresa', function () {
    $admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $asAdmin = $this->withToken($admin->createToken('t')->plainTextToken);

    $asAdmin->getJson('/api/v1/platform/companies')->assertForbidden();
    $asAdmin->getJson("/api/v1/platform/companies/{$this->company->id}")->assertForbidden();
    $asAdmin->patchJson("/api/v1/platform/companies/{$this->company->id}", ['ruc' => '10468536248'])->assertForbidden();
    $asAdmin->postJson("/api/v1/platform/companies/{$this->company->id}/deactivate")->assertForbidden();
});

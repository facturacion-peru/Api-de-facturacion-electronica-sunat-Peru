<?php

use App\Enums\CompanyRole;
use App\Enums\SunatStatus;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\SunatSetting;
use App\Models\User;
use App\Services\SunatConfigService;
use App\Tenancy\TenantContext;

/*
 * T032 · HU-4 Estado de la integración, visible para cualquier usuario.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->status = function () {
        app('auth')->forgetGuards();

        return $this->withToken($this->seller->createToken('t')->plainTextToken)->getJson('/api/v1/sunat/status');
    };
});

it('HU-4.1 sin configurar: no se puede emitir y dice qué falta', function () {
    ($this->status)()->assertOk()
        ->assertJsonPath('data.status', 'not_configured')
        ->assertJsonPath('data.environment', 'beta')
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.missing', ['Registra el usuario y la clave SOL.', 'Sube el certificado digital.'])
        ->assertJsonPath('data.certificate_days_to_expire', null);
});

it('validada con certificado vigente: se puede emitir en pruebas', function () {
    SunatSetting::create(['company_id' => $this->company->id, 'environment' => 'beta', 'status' => SunatStatus::Validated, 'sol_user' => 'U1', 'sol_password' => 'p']);
    Certificate::factory()->expiringInDays(20)->create(['company_id' => $this->company->id]);

    $response = ($this->status)()->assertOk()
        ->assertJsonPath('data.status', 'validated')
        ->assertJsonPath('data.can_issue', true)
        ->assertJsonPath('data.environment_label', 'Pruebas (beta) — sin valor legal')
        ->assertJsonPath('data.missing', []);

    expect($response->json('data.certificate_days_to_expire'))->toBeGreaterThanOrEqual(19)->toBeLessThanOrEqual(20)
        ->and($response->getContent())->not->toContain('"p"');
});

it('una empresa desactivada tiene la configuración inactiva', function () {
    SunatSetting::create(['company_id' => $this->company->id, 'environment' => 'beta', 'status' => SunatStatus::Validated]);
    $this->company->update(['active' => false]);

    [$status] = app(TenantContext::class)->run($this->company, fn () => app(SunatConfigService::class)->effectiveStatus($this->company->fresh()));

    expect($status)->toBe(SunatStatus::Inactive);
});

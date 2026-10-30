<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;

/*
 * T060 · HU-5: el administrador consulta y corrige los datos de su empresa.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create([
        'ruc' => '20131312955',
        'nombre_comercial' => 'Bodega Ana',
    ]);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->asAdmin = fn () => $this->withToken($this->admin->createToken('t')->plainTextToken);
    $this->asSeller = fn () => $this->withToken($this->seller->createToken('t')->plainTextToken);
});

it('cualquier rol consulta los datos de su empresa', function () {
    ($this->asSeller)()->getJson('/api/v1/company')
        ->assertOk()
        ->assertJsonPath('data.id', $this->company->id)
        ->assertJsonPath('data.ruc', '20131312955')
        ->assertJsonPath('data.fiscal_address.ubigeo', '150101');
});

it('HU-5.1 el administrador edita nombre comercial, correo y teléfono, y queda auditado', function () {
    ($this->asAdmin)()->patchJson('/api/v1/company', [
        'nombre_comercial' => 'Bodega Ana e Hijos',
        'email' => 'ventas@bodega-ana.pe',
        'phone' => '912345678',
    ])->assertOk()
        ->assertJsonPath('data.nombre_comercial', 'Bodega Ana e Hijos')
        ->assertJsonPath('data.email', 'ventas@bodega-ana.pe');

    $log = AuditLog::withoutTenancy()->where('action', 'company.updated')->sole();

    expect($log->changes['nombre_comercial'])->toBe(['from' => 'Bodega Ana', 'to' => 'Bodega Ana e Hijos'])
        ->and($log->company_id)->toBe($this->company->id);
});

it('HU-5.2 impide cambiar el RUC y los datos que corrige la plataforma', function () {
    ($this->asAdmin)()->patchJson('/api/v1/company', [
        'ruc' => '20100070970',
        'razon_social' => 'Otra S.A.C.',
        'tax_regime' => 'general',
        'active' => false,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.ruc.0', 'Este dato solo lo puede corregir el administrador de la plataforma.')
        ->assertJsonValidationErrors(['razon_social', 'tax_regime', 'active']);

    expect($this->company->fresh()->ruc)->toBe('20131312955');
});

it('valida correo y teléfono', function () {
    ($this->asAdmin)()->patchJson('/api/v1/company', ['email' => 'no-es-correo', 'phone' => str_repeat('9', 40)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'phone']);
});

// El logo (spec 016, en S3) se prueba en CompanyLogoTest.

it('el vendedor no edita la empresa', function () {
    ($this->asSeller)()->patchJson('/api/v1/company', ['nombre_comercial' => 'X'])->assertForbidden();
    ($this->asSeller)()->postJson('/api/v1/company/logo', [])->assertForbidden();
});

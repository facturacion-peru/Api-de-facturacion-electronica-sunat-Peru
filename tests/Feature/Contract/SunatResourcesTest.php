<?php

use App\Enums\CompanyRole;
use App\Http\Resources\SeriesResource;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\Series;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
 * T052 · Contrato de los recursos de SUNAT (principio III).
 */

it('la configuración expone exactamente los campos declarados, sin secretos', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $admin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();
    Certificate::factory()->create(['company_id' => $company->id]);

    $data = $this->withToken($admin->createToken('t')->plainTextToken)->getJson('/api/v1/sunat/settings')->json('data');

    expect(array_keys($data))->toBe([
        'company_id', 'environment', 'environment_label', 'status', 'status_label', 'sol_user_masked', 'has_sol_password',
        'sol_verified', 'certificate', 'certificates_history', 'last_validated_at', 'last_validation_error',
    ])->and(array_keys($data['certificate']))->toBe(['subject', 'ruc', 'valid_from', 'valid_to', 'days_to_expire', 'uploaded_by']);
});

it('el estado expone exactamente los campos declarados', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $seller = User::factory()->forCompany($company)->create();

    expect(array_keys($this->withToken($seller->createToken('t')->plainTextToken)->getJson('/api/v1/sunat/status')->json('data')))->toBe([
        'environment', 'environment_label', 'status', 'status_label', 'reason', 'can_issue', 'missing', 'sol_verified', 'certificate_days_to_expire',
    ]);
});

it('SeriesResource expone exactamente los campos declarados', function () {
    $series = Series::factory()->create();
    app(TenantContext::class)->set($series->company);

    expect(array_keys(SeriesResource::make($series)->resolve()))->toBe([
        'id', 'document_type', 'document_type_label', 'code', 'last_number', 'next_number', 'active', 'establishment_code',
    ]);
});

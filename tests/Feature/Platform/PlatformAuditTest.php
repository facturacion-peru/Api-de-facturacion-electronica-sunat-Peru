<?php

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
 * T042 · HU-6 Auditoría de la plataforma: acciones de sus administradores
 * e inicios de sesión de la plataforma, no la actividad de las empresas.
 */

beforeEach(function () {
    $this->root = User::factory()->platformAdmin()->create(['name' => 'Raúl Plataforma']);
    $this->company = Company::factory()->withMainEstablishment()->create(['razon_social' => 'Bodega Alfa S.A.C.']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();

    $as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken);
    };
    $this->postJson('/api/v1/auth/login', ['email' => $this->root->email, 'password' => 'password'])->assertOk();
    $as($this->root)->postJson("/api/v1/platform/companies/{$this->company->id}/deactivate", ['reason' => 'Falta de pago'])->assertOk();
    $as($this->root)->postJson("/api/v1/platform/companies/{$this->company->id}/activate")->assertOk();
    // Actividad propia de la empresa: no es de la plataforma.
    app(TenantContext::class)->run($this->company, fn () => app(AuditLogger::class)->record('product.created', $this->company, actor: $this->admin));

    $this->get = fn (string $query = '') => $as($this->root)->getJson('/api/v1/platform/audit-logs'.$query);
});

it('muestra solo las acciones de la plataforma, con la empresa afectada', function () {
    $data = ($this->get)()->assertOk()->json('data');

    expect(array_column($data, 'action'))->toBe(['company.activated', 'company.deactivated', 'auth.login'])
        ->and($data[1]['actor']['name'])->toBe('Raúl Plataforma')
        ->and($data[1]['changes'])->toBe(['reason' => 'Falta de pago'])
        ->and($data[1]['company'])->toBe(['id' => $this->company->id, 'razon_social' => 'Bodega Alfa S.A.C.'])
        ->and($data[2]['company'])->toBeNull();
});

it('filtra por acción y por empresa', function () {
    expect(array_column(($this->get)('?action=company.deactivated')->json('data'), 'action'))->toBe(['company.deactivated'])
        ->and(array_column(($this->get)('?company_id='.$this->company->id)->json('data'), 'action'))->toBe(['company.activated', 'company.deactivated']);
});

<?php

use App\Audit\AuditLogger;
use App\Audit\Exceptions\AuditLogImmutable;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
 * T019 · Auditoría inmutable y sin secretos (RF-040/041, HU-6.2/6.3).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->actor = User::factory()->forCompany($this->company)->create();
    $this->logger = app(AuditLogger::class);
});

it('registra actor, empresa, acción, registro, IP y agente', function () {
    $this->actingAs($this->actor);
    app(TenantContext::class)->set($this->company);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    request()->headers->set('User-Agent', 'Pruebas/1.0');

    $log = $this->logger->record('user.updated', $this->actor, ['name' => 'Ana']);

    expect($log->company_id)->toBe($this->company->id)
        ->and($log->actor_id)->toBe($this->actor->id)
        ->and($log->action)->toBe('user.updated')
        ->and($log->auditable_type)->toBe($this->actor->getMorphClass())
        ->and($log->auditable_id)->toBe($this->actor->id)
        ->and($log->changes)->toBe(['name' => 'Ana'])
        ->and($log->ip)->toBe('203.0.113.7')
        ->and($log->user_agent)->toBe('Pruebas/1.0');
});

it('toma la empresa del propio registro auditado si no hay contexto', function () {
    $log = $this->logger->record('company.created', $this->company);

    expect($log->company_id)->toBe($this->company->id);
});

it('admite eventos de plataforma sin empresa', function () {
    $log = $this->logger->record('auth.login_failed', changes: ['email' => 'nadie@example.com']);

    expect($log->company_id)->toBeNull();
});

it('impide modificar un registro de auditoría', function () {
    $log = $this->logger->record('company.created', $this->company);

    AuditLog::withoutTenancy()->find($log->id)->update(['action' => 'otra']);
})->throws(AuditLogImmutable::class);

it('impide borrar un registro de auditoría', function () {
    $log = $this->logger->record('company.created', $this->company);

    AuditLog::withoutTenancy()->find($log->id)->delete();
})->throws(AuditLogImmutable::class);

it('elimina las claves sensibles, también anidadas', function () {
    $log = $this->logger->record('company.updated', $this->company, [
        'nombre_comercial' => 'Bodega Ana',
        'password' => 'secreto-1',
        'api_token' => 'secreto-2',
        'client_secret' => 'secreto-3',
        'sunat' => ['usuario' => 'MODDATOS', 'clave_sol' => 'secreto-4', 'certificado_password' => 'secreto-5'],
    ]);

    expect($log->changes)->toBe([
        'nombre_comercial' => 'Bodega Ana',
        'sunat' => ['usuario' => 'MODDATOS'],
    ]);
});

it('elimina los atributos ocultos del modelo auditado', function () {
    $log = $this->logger->record('user.updated', $this->actor, [
        'name' => 'Ana',
        'remember_token' => 'secreto-6',
    ]);

    expect($log->changes)->toBe(['name' => 'Ana']);
});

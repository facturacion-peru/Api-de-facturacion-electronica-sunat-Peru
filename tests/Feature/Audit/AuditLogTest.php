<?php

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Tenancy\TenantContext;
use Database\Factories\EstablishmentFactory;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
 * T080 · HU-6: el administrador consulta la auditoría de su empresa
 * (RF-040/041).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create(['name' => 'Ana']);
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create(['name' => 'Luis']);
    $this->asAdmin = fn () => $this->withToken($this->admin->createToken('t')->plainTextToken);
});

function auditAs(User $actor, Company $company, string $action, ?string $at = null): void
{
    app(TenantContext::class)->run($company, function () use ($actor, $action, $at) {
        $log = app(AuditLogger::class)->record($action, $actor, actor: $actor);

        if ($at !== null) {
            AuditLog::query()->whereKey($log->id)->toBase()->update(['created_at' => $at]);
        }
    });
}

it('HU-6.1 lista la auditoría de su empresa, paginada y de la más reciente a la más antigua', function () {
    auditAs($this->admin, $this->company, 'user.updated', '2026-09-01 10:00:00');
    auditAs($this->seller, $this->company, 'company.updated', '2026-09-02 10:00:00');
    auditAs($this->admin, Company::factory()->create(), 'user.updated');

    $response = ($this->asAdmin)()->getJson('/api/v1/audit-logs')->assertOk();

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.0.action'))->toBe('company.updated')
        ->and($response->json('data.0.actor.name'))->toBe('Luis')
        ->and($response->json('data.0'))->toHaveKeys(['id', 'action', 'actor', 'auditable_type', 'auditable_id', 'changes', 'ip', 'created_at']);
});

it('filtra por acción, usuario y fechas', function () {
    auditAs($this->admin, $this->company, 'user.updated', '2026-09-01 10:00:00');
    auditAs($this->seller, $this->company, 'company.updated', '2026-09-10 10:00:00');
    auditAs($this->admin, $this->company, 'company.updated', '2026-09-20 10:00:00');

    $query = fn (array $params) => collect(($this->asAdmin)()->getJson('/api/v1/audit-logs?'.http_build_query($params))->assertOk()->json('data'));

    expect($query(['action' => 'company.updated']))->toHaveCount(2)
        ->and($query(['actor_id' => $this->seller->id]))->toHaveCount(1)
        ->and($query(['from' => '2026-09-05', 'to' => '2026-09-15'])->pluck('actor.name')->all())->toBe(['Luis']);
});

it('valida los filtros', function () {
    ($this->asAdmin)()->getJson('/api/v1/audit-logs?from=ayer&to=2026-01-01&actor_id=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from', 'actor_id']);
});

it('HU-6.2 no existe ninguna ruta para modificar o borrar la auditoría', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'audit'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()->sort()->values()->all();

    expect($routes)->toBe(['GET', 'HEAD']);
});

it('el vendedor no ve la auditoría', function () {
    $this->withToken($this->seller->createToken('t')->plainTextToken)
        ->getJson('/api/v1/audit-logs')->assertForbidden();
});

it('RF-040 cada acción sensible deja su registro', function () {
    Notification::fake();
    EstablishmentFactory::ensureLimaUbigeo();
    $root = User::factory()->platformAdmin()->create();
    $asRoot = fn () => $this->withToken($root->createToken('t')->plainTextToken);
    $fresh = fn () => app('auth')->forgetGuards();

    // Empresa: alta, cambio, desactivación y reactivación.
    $asRoot()->postJson('/api/v1/platform/companies', [
        'ruc' => '20100070970', 'razon_social' => 'Nueva S.A.C.', 'tax_regime' => 'rmt',
        'email' => 'nueva@example.com', 'address' => 'Jr. Uno 1', 'ubigeo' => '150101', 'admin_email' => 'jefe@example.com',
    ])->assertCreated();
    $asRoot()->patchJson("/api/v1/platform/companies/{$this->company->id}", ['razon_social' => 'Cambiada S.A.C.'])->assertOk();
    $asRoot()->postJson("/api/v1/platform/companies/{$this->company->id}/deactivate")->assertOk();
    $asRoot()->postJson("/api/v1/platform/companies/{$this->company->id}/activate")->assertOk();
    $fresh();

    // Usuarios: invitación, aceptación, cambio de rol, desactivación y reactivación.
    ($this->asAdmin)()->postJson('/api/v1/invitations', ['email' => 'nuevo@example.com', 'role' => 'seller'])->assertCreated();
    $token = null;
    Notification::assertSentOnDemand(InvitationNotification::class, function ($n, $c, $notifiable) use (&$token) {
        if ($notifiable->routes['mail'] === 'nuevo@example.com') {
            $token = $n->token;
        }

        return true;
    });
    $fresh();
    $this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Nuevo', 'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123'])->assertCreated();
    $fresh();
    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->seller->id}", ['role' => 'company_admin'])->assertOk();
    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->seller->id}", ['active' => false])->assertOk();
    ($this->asAdmin)()->patchJson("/api/v1/users/{$this->seller->id}", ['active' => true])->assertOk();
    ($this->asAdmin)()->patchJson('/api/v1/company', ['phone' => '999888777'])->assertOk();
    $fresh();

    // Acceso: login fallido y recuperación de contraseña.
    $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'incorrecta-123'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $this->admin->email])->assertOk();
    $resetToken = null;
    Notification::assertSentTo($this->admin, ResetPasswordNotification::class, function ($n) use (&$resetToken) {
        $resetToken = $n->token;

        return true;
    });
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $resetToken, 'email' => $this->admin->email,
        'password' => 'clave-nueva-456', 'password_confirmation' => 'clave-nueva-456',
    ])->assertOk();

    $actions = AuditLog::withoutTenancy()->distinct()->pluck('action')->sort()->values()->all();

    expect($actions)->toContain(
        'company.created', 'company.updated', 'company.deactivated', 'company.activated',
        'invitation.created', 'invitation.accepted',
        'user.role_changed', 'user.deactivated', 'user.activated',
        'auth.login_failed', 'auth.password_reset',
    );
});

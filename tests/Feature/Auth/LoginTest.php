<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/*
 * T042 · HU-2.3 y HU-2.4: iniciar y cerrar sesión (RF-012), con bloqueo
 * temporal tras intentos fallidos y auditoría de los fallos (RF-040).
 */

beforeEach(function () {
    RateLimiter::clear('login');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->user = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create([
        'email' => 'ana@example.com',
        'password' => 'clave-segura-123',
    ]);
});

function login(string $email, string $password)
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

it('HU-2.3 devuelve token, usuario, empresa y rol', function () {
    login(' Ana@Example.com ', 'clave-segura-123')
        ->assertOk()
        ->assertJsonPath('data.user.email', 'ana@example.com')
        ->assertJsonPath('data.company.id', $this->company->id)
        ->assertJsonPath('data.role', 'company_admin')
        ->assertJsonPath('data.platform_admin', false)
        ->assertJsonStructure(['data' => ['token', 'expires_at']]);

    expect($this->user->fresh()->last_login_at)->not->toBeNull()
        ->and($this->user->tokens()->count())->toBe(1);
});

it('responde lo mismo con contraseña errónea o correo inexistente', function () {
    $wrongPassword = login('ana@example.com', 'otra-clave-999')->assertUnprocessable();
    $unknownEmail = login('nadie@example.com', 'otra-clave-999')->assertUnprocessable();

    expect($wrongPassword->json('errors.email.0'))->toBe('Las credenciales no son correctas.')
        ->and($unknownEmail->json())->toBe($wrongPassword->json());
});

it('audita los intentos fallidos sin guardar la contraseña', function () {
    login('ana@example.com', 'otra-clave-999');
    login('nadie@example.com', 'otra-clave-999');

    $logs = AuditLog::withoutTenancy()->where('action', 'auth.login_failed')->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->company_id)->toBe($this->company->id)
        ->and($logs[1]->company_id)->toBeNull()
        ->and(json_encode($logs->pluck('changes')))->not->toContain('otra-clave-999');
});

it('HU-2.4 bloquea temporalmente tras 5 intentos fallidos', function () {
    foreach (range(1, 5) as $i) {
        login('ana@example.com', 'otra-clave-999')->assertUnprocessable();
    }

    login('ana@example.com', 'clave-segura-123')
        ->assertTooManyRequests()
        ->assertJsonPath('success', false);

    expect($this->user->tokens()->count())->toBe(0);
});

it('un acceso correcto reinicia el contador de intentos', function () {
    foreach (range(1, 4) as $i) {
        login('ana@example.com', 'otra-clave-999');
    }

    login('ana@example.com', 'clave-segura-123')->assertOk();

    foreach (range(1, 4) as $i) {
        login('ana@example.com', 'otra-clave-999')->assertUnprocessable();
    }
});

it('HU-2.6 rechaza el acceso de un usuario o una empresa desactivados', function (string $caso) {
    match ($caso) {
        'usuario' => $this->user->update(['active' => false]),
        'pertenencia' => $this->user->membership->update(['active' => false]),
        'empresa' => $this->company->update(['active' => false]),
    };

    login('ana@example.com', 'clave-segura-123')
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Tu cuenta o tu empresa están desactivadas.');

    expect($this->user->tokens()->count())->toBe(0);
})->with(['usuario', 'pertenencia', 'empresa']);

it('permite el acceso del administrador de la plataforma, sin empresa', function () {
    User::factory()->platformAdmin()->create(['email' => 'root@example.com', 'password' => 'clave-segura-123']);

    login('root@example.com', 'clave-segura-123')
        ->assertOk()
        ->assertJsonPath('data.platform_admin', true)
        ->assertJsonPath('data.company', null)
        ->assertJsonPath('data.role', null);
});

it('me devuelve la sesión sin token', function () {
    $token = $this->user->createToken('app')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', 'ana@example.com')
        ->assertJsonPath('data.role', 'company_admin')
        ->assertJsonMissingPath('data.token');
});

it('logout revoca el token actual', function () {
    $token = $this->user->createToken('app')->plainTextToken;
    $this->user->createToken('otro-dispositivo');

    $this->withToken($token)->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($this->user->tokens()->pluck('name')->all())->toBe(['otro-dispositivo']);
});

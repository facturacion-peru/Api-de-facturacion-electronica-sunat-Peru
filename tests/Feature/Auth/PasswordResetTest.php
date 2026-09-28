<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * T045 · HU-2.7 y HU-2.8: recuperar la contraseña por correo (RF-016, A-25).
 */

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->forCompany(Company::factory()->withMainEstablishment()->create())
        ->create(['email' => 'ana@example.com', 'password' => 'clave-antigua-123']);
});

function requestReset(string $email)
{
    return test()->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
}

function sentResetToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return $token;
}

it('HU-2.7 envía un enlace al frontend', function () {
    requestReset(' Ana@Example.com ')->assertOk()->assertJsonPath('success', true);

    Notification::assertSentTo($this->user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) {
        return str_starts_with($n->url($this->user), config('app.frontend_url').'/restablecer?token=')
            && str_contains($n->url($this->user), 'email='.urlencode('ana@example.com'));
    });
});

it('HU-2.7 responde lo mismo exista o no el correo', function () {
    $exists = requestReset('ana@example.com');
    $unknown = requestReset('nadie@example.com');

    expect($unknown->status())->toBe($exists->status())
        ->and($unknown->json())->toBe($exists->json());

    Notification::assertCount(1);
});

it('no envía enlace a una cuenta desactivada, con la misma respuesta', function () {
    $this->user->update(['active' => false]);

    requestReset('ana@example.com')->assertOk();

    Notification::assertNothingSent();
});

it('HU-2.8 cambia la contraseña, cierra todas las sesiones y se audita', function () {
    $this->user->createToken('celular');
    $this->user->createToken('laptop');
    requestReset('ana@example.com');

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => sentResetToken($this->user),
        'email' => 'ana@example.com',
        'password' => 'clave-nueva-456',
        'password_confirmation' => 'clave-nueva-456',
    ])->assertOk()->assertJsonPath('success', true);

    $user = $this->user->fresh();

    expect(Hash::check('clave-nueva-456', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(AuditLog::withoutTenancy()->where('action', 'auth.password_reset')->count())->toBe(1);
});

it('el enlace es de un solo uso', function () {
    requestReset('ana@example.com');
    $token = sentResetToken($this->user);
    $payload = ['token' => $token, 'email' => 'ana@example.com', 'password' => 'clave-nueva-456', 'password_confirmation' => 'clave-nueva-456'];

    $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

    $this->postJson('/api/v1/auth/reset-password', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.token.0', 'El enlace no es válido o venció. Pide uno nuevo.');
});

it('rechaza un enlace vencido (60 minutos)', function () {
    requestReset('ana@example.com');
    $token = sentResetToken($this->user);

    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => 'ana@example.com',
        'password' => 'clave-nueva-456', 'password_confirmation' => 'clave-nueva-456',
    ])->assertUnprocessable()->assertJsonValidationErrors(['token']);

    expect(Hash::check('clave-antigua-123', $this->user->fresh()->password))->toBeTrue();
});

it('aplica la política de contraseñas', function () {
    requestReset('ana@example.com');

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => sentResetToken($this->user), 'email' => 'ana@example.com',
        'password' => 'corta', 'password_confirmation' => 'corta',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

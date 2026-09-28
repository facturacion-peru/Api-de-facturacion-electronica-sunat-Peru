<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * T024 · El primer administrador de la plataforma se crea por consola,
 * nunca por HTTP (RF-050, constitución 1.1.1).
 */

it('crea un administrador de la plataforma', function () {
    $this->artisan('platform:create-admin')
        ->expectsQuestion('Nombre', 'Ana Admin')
        ->expectsQuestion('Correo', ' Ana@Example.com ')
        ->expectsQuestion('Contraseña', 'clave-segura-123')
        ->expectsQuestion('Repite la contraseña', 'clave-segura-123')
        ->expectsOutputToContain('Administrador de la plataforma creado')
        ->assertSuccessful();

    $user = User::where('email', 'ana@example.com')->firstOrFail();

    expect($user->is_platform_admin)->toBeTrue()
        ->and($user->membership)->toBeNull()
        ->and(Hash::check('clave-segura-123', $user->password))->toBeTrue()
        ->and(AuditLog::withoutTenancy()->where('action', 'platform_admin.created')->count())->toBe(1);
});

it('rechaza un correo ya registrado', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->artisan('platform:create-admin')
        ->expectsQuestion('Nombre', 'Ana')
        ->expectsQuestion('Correo', 'ana@example.com')
        ->expectsQuestion('Contraseña', 'clave-segura-123')
        ->expectsQuestion('Repite la contraseña', 'clave-segura-123')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('rechaza contraseñas que no coinciden', function () {
    $this->artisan('platform:create-admin')
        ->expectsQuestion('Nombre', 'Ana')
        ->expectsQuestion('Correo', 'ana@example.com')
        ->expectsQuestion('Contraseña', 'clave-segura-123')
        ->expectsQuestion('Repite la contraseña', 'otra-clave-456')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('rechaza una contraseña débil', function () {
    $this->artisan('platform:create-admin')
        ->expectsQuestion('Nombre', 'Ana')
        ->expectsQuestion('Correo', 'ana@example.com')
        ->expectsQuestion('Contraseña', 'corta')
        ->expectsQuestion('Repite la contraseña', 'corta')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

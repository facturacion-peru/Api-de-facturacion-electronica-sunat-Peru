<?php

use App\Models\Company;
use App\Models\User;

/*
 * T044 · HU-2.6 y CE-004: una desactivación corta una sesión ya abierta en
 * la siguiente petición (RF-013).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->user = User::factory()->forCompany($this->company)->create();
    $this->token = $this->user->createToken('app')->plainTextToken;
});

/** Simula una petición nueva: el guard no conserva el usuario de la anterior. */
function nextRequest(): void
{
    app('auth')->forgetGuards();
}

it('corta la sesión abierta cuando se desactiva', function (string $caso) {
    $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertOk();

    match ($caso) {
        'el usuario' => $this->user->update(['active' => false]),
        'su pertenencia' => $this->user->membership->update(['active' => false]),
        'su empresa' => $this->company->update(['active' => false]),
    };

    nextRequest();
    $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect($this->user->tokens()->count())->toBe(0);
})->with(['el usuario', 'su pertenencia', 'su empresa']);

it('un token revocado ya no sirve aunque se reactive la cuenta', function () {
    $this->user->update(['active' => false]);
    $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->user->update(['active' => true]);
    nextRequest();

    $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

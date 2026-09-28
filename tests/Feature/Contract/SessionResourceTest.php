<?php

use App\Enums\CompanyRole;
use App\Http\Resources\SessionResource;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\User;

/*
 * T047 · Contrato de SessionResource y UserResource (principio III).
 */

it('UserResource expone solo id, nombre y correo', function () {
    $user = User::factory()->create();

    expect(array_keys(UserResource::make($user)->resolve()))->toBe(['id', 'name', 'email']);
});

it('SessionResource con token expone la sesión completa', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $user = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create()->load('membership.company');

    $data = (new SessionResource($user, 'token-de-prueba'))->resolve();

    expect(array_keys($data))->toBe(['token', 'expires_at', 'user', 'platform_admin', 'company', 'role'])
        ->and(array_keys($data['user']->resolve()))->toBe(['id', 'name', 'email'])
        ->and(array_keys($data['company']))->toBe(['id', 'ruc', 'razon_social', 'nombre_comercial']);
});

it('SessionResource sin token omite token y vencimiento', function () {
    $user = User::factory()->platformAdmin()->create();

    $json = (new SessionResource($user))->response()->getData(true);

    expect(array_keys($json['data']))->toBe(['user', 'platform_admin', 'company', 'role'])
        ->and($json['success'])->toBeTrue();
});

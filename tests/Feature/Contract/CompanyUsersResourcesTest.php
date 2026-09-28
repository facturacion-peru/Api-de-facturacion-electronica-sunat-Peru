<?php

use App\Http\Resources\CompanyUserResource;
use App\Http\Resources\InvitationResource;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;

/*
 * T054 · Contrato de InvitationResource y CompanyUserResource (principio III).
 */

it('InvitationResource expone exactamente los campos declarados, sin el token', function () {
    $invitation = Invitation::factory()->create()->load('inviter');

    expect(array_keys(InvitationResource::make($invitation)->resolve()))
        ->toBe(['id', 'email', 'role', 'expires_at', 'expired', 'invited_by', 'created_at']);
});

it('CompanyUserResource expone exactamente los campos declarados', function () {
    $user = User::factory()->forCompany(Company::factory()->create())->create()->load('membership');

    expect(array_keys(CompanyUserResource::make($user)->resolve()))
        ->toBe(['id', 'name', 'email', 'role', 'active', 'last_login_at']);
});

it('los listados usan el sobre { success, data, meta.total }', function () {
    $user = User::factory()->forCompany(Company::factory()->create())->create()->load('membership');

    $json = CompanyUserResource::collection(collect([$user]))->response()->getData(true);

    expect(array_keys($json))->toBe(['data', 'success', 'meta'])
        ->and($json['meta'])->toBe(['total' => 1]);
});

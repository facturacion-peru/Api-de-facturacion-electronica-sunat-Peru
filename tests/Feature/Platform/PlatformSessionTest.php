<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;

/*
 * T010 · A-38: la sesión de la plataforma dura 8 h y cada inicio de sesión
 * se audita. Los usuarios de empresa siguen con la expiración global (24 h).
 */

function loginAs(User $user): string
{
    return test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('data.token');
}

it('el token de la plataforma vence a las 8 horas', function () {
    $root = User::factory()->platformAdmin()->create();
    $token = loginAs($root);

    $this->travel(479)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/platform/companies')->assertOk();

    $this->travel(2)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/platform/companies')->assertUnauthorized();
});

it('el token de empresa no cambia: sigue valiendo pasadas las 8 horas', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $admin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();
    $token = loginAs($admin);

    $this->travel(9)->hours();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('audita el inicio de sesión de la plataforma, con IP y sin empresa', function () {
    $root = User::factory()->platformAdmin()->create();
    loginAs($root);

    $log = AuditLog::withoutTenancy()->where('action', 'auth.login')->sole();
    expect($log->actor_id)->toBe($root->id)
        ->and($log->company_id)->toBeNull()
        ->and($log->ip)->toBe('127.0.0.1');
});

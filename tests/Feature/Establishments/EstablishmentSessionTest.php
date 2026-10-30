<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * T008 · RF-010, A-87, A-91 (spec 017): la sesión dice en qué locales puede
 * trabajar el usuario y cuántos tiene la empresa, desde que nace (login o
 * invitación). La invitación de un vendedor lleva sus locales.
 */

beforeEach(function () {
    Notification::fake();
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->second = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001', 'name' => 'Sucursal']);
    Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0002', 'active' => false]);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create(['password' => 'clave-segura-123']);
    $this->seller = User::factory()->forCompany($this->company)->create(['password' => 'clave-segura-123']);
    $this->seller->establishments()->attach($this->second->id, ['company_id' => $this->company->id]);
    $this->call = function (string $method, string $uri, array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
});

it('el login trae los locales permitidos y cuántos activos tiene la empresa', function () {
    $seller = $this->postJson('/api/v1/auth/login', ['email' => $this->seller->email, 'password' => 'clave-segura-123'])->assertOk();
    $admin = $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'clave-segura-123'])->assertOk();

    expect(collect($seller->json('data.establishments'))->pluck('code')->all())->toBe(['0001'])
        ->and($seller->json('data.company.active_establishments_count'))->toBe(2)
        ->and(collect($admin->json('data.establishments'))->pluck('code')->all())->toBe(['0000', '0001'])
        ->and(array_keys($seller->json('data.establishments.0')))->toBe(['id', 'code', 'name', 'address', 'ubigeo', 'district', 'is_main', 'active', 'code_locked']);
});

it('/auth/me trae lo mismo; el administrador de la plataforma, ningún local', function () {
    ($this->call)('GET', '/api/v1/auth/me', [], $this->seller)
        ->assertOk()
        ->assertJsonPath('data.establishments.0.name', 'Sucursal')
        ->assertJsonPath('data.company.active_establishments_count', 2);

    $platform = User::factory()->platformAdmin()->create();
    ($this->call)('GET', '/api/v1/auth/me', [], $platform)
        ->assertOk()
        ->assertJsonPath('data.establishments', [])
        ->assertJsonPath('data.company', null);
});

it('«Mi empresa» trae los locales permitidos y la cuenta de activos', function () {
    ($this->call)('GET', '/api/v1/company', [], $this->seller)
        ->assertOk()
        ->assertJsonPath('data.active_establishments_count', 2)
        ->assertJsonCount(1, 'data.establishments');
});

it('la invitación de un vendedor guarda sus locales y al aceptarla se asignan', function () {
    $response = ($this->call)('POST', '/api/v1/invitations', ['email' => 'nuevo@example.com', 'role' => 'seller', 'establishment_ids' => [$this->second->id]])
        ->assertCreated()
        ->assertJsonPath('data.establishment_ids', [$this->second->id]);

    $invitation = Invitation::withoutTenancy()->findOrFail($response->json('data.id'));
    $token = str_repeat('b', 64);
    $invitation->forceFill(['token_hash' => Invitation::hashToken($token)])->save();

    $session = $this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Nuevo', 'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123'])
        ->assertCreated();

    expect(collect($session->json('data.establishments'))->pluck('code')->all())->toBe(['0001'])
        ->and($session->json('data.company.active_establishments_count'))->toBe(2)
        ->and(User::where('email', 'nuevo@example.com')->sole()->establishments()->withoutGlobalScopes()->pluck('establishments.id')->all())->toBe([$this->second->id]);
});

it('con varios locales activos, invitar a un vendedor pide al menos un local activo', function (Closure $ids) {
    ($this->call)('POST', '/api/v1/invitations', ['email' => 'nuevo@example.com', 'role' => 'seller', 'establishment_ids' => $ids($this)])
        ->assertUnprocessable()->assertJsonValidationErrors(['establishment_ids']);
})->with([
    'sin locales' => [fn () => []],
    'inactivo' => [fn ($t) => [Establishment::withoutTenancy()->where('code', '0002')->value('id')]],
    'de otra empresa' => [fn () => [Establishment::factory()->create()->id]],
]);

it('al invitar a un administrador no se piden locales', function () {
    ($this->call)('POST', '/api/v1/invitations', ['email' => 'jefe@example.com', 'role' => 'company_admin'])
        ->assertCreated()->assertJsonPath('data.establishment_ids', null);
});

it('A-91 con un solo local activo, el vendedor invitado queda en ese sin pedirlo', function () {
    $this->second->update(['active' => false]);

    ($this->call)('POST', '/api/v1/invitations', ['email' => 'nuevo@example.com', 'role' => 'seller'])->assertCreated();

    $invitation = Invitation::withoutTenancy()->where('email', 'nuevo@example.com')->sole();
    $token = str_repeat('c', 64);
    $invitation->forceFill(['token_hash' => Invitation::hashToken($token)])->save();
    $this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Nuevo', 'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123'])
        ->assertCreated()
        ->assertJsonPath('data.establishments.0.code', '0000')
        ->assertJsonPath('data.company.active_establishments_count', 1);
});

it('al aceptar se descartan los locales que se desactivaron después de invitar', function () {
    ($this->call)('POST', '/api/v1/invitations', ['email' => 'nuevo@example.com', 'role' => 'seller', 'establishment_ids' => [$this->main->id, $this->second->id]])->assertCreated();
    $this->second->update(['active' => false]);

    $invitation = Invitation::withoutTenancy()->where('email', 'nuevo@example.com')->sole();
    $token = str_repeat('d', 64);
    $invitation->forceFill(['token_hash' => Invitation::hashToken($token)])->save();

    $this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Nuevo', 'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123'])
        ->assertCreated();

    expect(User::where('email', 'nuevo@example.com')->sole()->establishments()->withoutGlobalScopes()->pluck('establishments.id')->all())->toBe([$this->main->id]);
});

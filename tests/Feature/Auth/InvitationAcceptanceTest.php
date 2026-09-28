<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Hash;

/*
 * T040 · HU-2.1 y HU-2.2: activar la cuenta desde una invitación (RF-010).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create(['razon_social' => 'Bodega Ana S.A.C.']);
    $this->token = str_repeat('a', 64);
    $this->invitation = Invitation::factory()->withToken($this->token)->create([
        'company_id' => $this->company->id,
        'email' => 'ana@example.com',
        'role' => CompanyRole::CompanyAdmin,
    ]);
    $this->valid = ['name' => 'Ana Pérez', 'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123'];
});

it('muestra los datos de una invitación vigente', function () {
    $this->getJson("/api/v1/invitations/{$this->token}")
        ->assertOk()
        ->assertJsonPath('data.email', 'ana@example.com')
        ->assertJsonPath('data.role', 'company_admin')
        ->assertJsonPath('data.company.razon_social', 'Bodega Ana S.A.C.')
        ->assertJsonMissingPath('data.token_hash');
});

it('HU-2.1 al aceptar crea la cuenta con el rol invitado y devuelve una sesión', function () {
    $response = $this->postJson("/api/v1/invitations/{$this->token}/accept", $this->valid);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'ana@example.com')
        ->assertJsonPath('data.role', 'company_admin')
        ->assertJsonPath('data.company.id', $this->company->id);

    $user = User::where('email', 'ana@example.com')->firstOrFail();

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($user->name)->toBe('Ana Pérez')
        ->and(Hash::check('clave-segura-123', $user->password))->toBeTrue()
        ->and($user->membership->company_id)->toBe($this->company->id)
        ->and($user->membership->role)->toBe(CompanyRole::CompanyAdmin)
        ->and($user->tokens()->count())->toBe(1)
        ->and($this->invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(AuditLog::withoutTenancy()->where('action', 'invitation.accepted')->count())->toBe(1);
});

it('HU-2.2 rechaza una invitación ya usada', function () {
    $this->postJson("/api/v1/invitations/{$this->token}/accept", $this->valid)->assertCreated();

    $this->postJson("/api/v1/invitations/{$this->token}/accept", $this->valid)
        ->assertStatus(410)
        ->assertJsonPath('message', 'La invitación ya no es válida. Pide una nueva.');

    expect(User::count())->toBe(1);
});

it('HU-2.2 rechaza una invitación vencida', function () {
    $this->invitation->update(['expires_at' => now()->subMinute()]);

    $this->getJson("/api/v1/invitations/{$this->token}")->assertStatus(410);
    $this->postJson("/api/v1/invitations/{$this->token}/accept", $this->valid)->assertStatus(410);

    expect(User::count())->toBe(0);
});

it('responde 404 a un token desconocido', function () {
    $this->getJson('/api/v1/invitations/'.str_repeat('b', 64))->assertNotFound();
});

it('rechaza una invitación de una empresa desactivada', function () {
    $this->company->update(['active' => false]);

    $this->postJson("/api/v1/invitations/{$this->token}/accept", $this->valid)->assertStatus(410);
});

it('valida nombre y contraseña', function () {
    $this->postJson("/api/v1/invitations/{$this->token}/accept", [
        'name' => '',
        'password' => 'corta',
        'password_confirmation' => 'otra',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'password']);
});

it('rechaza si el correo ya tiene cuenta', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->postJson("/api/v1/invitations/{$this->token}/accept", $this->valid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('limita los intentos', function () {
    foreach (range(1, AppServiceProvider::PUBLIC_AUTH_PER_MINUTE) as $i) {
        $this->getJson('/api/v1/invitations/'.str_repeat('c', 64));
    }

    $this->getJson('/api/v1/invitations/'.str_repeat('c', 64))->assertTooManyRequests();
});

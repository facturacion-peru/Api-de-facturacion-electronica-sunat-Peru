<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;

/*
 * T050 · HU-3.1, HU-3.2 y HU-3.4: el administrador de empresa invita,
 * reenvía y cancela invitaciones.
 */

beforeEach(function () {
    Notification::fake();
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->asAdmin = fn () => $this->withToken($this->admin->createToken('t')->plainTextToken);
});

it('HU-3.1 invita a un vendedor', function () {
    ($this->asAdmin)()->postJson('/api/v1/invitations', ['email' => ' Luis@Example.com ', 'role' => 'seller'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'luis@example.com')
        ->assertJsonPath('data.role', 'seller')
        ->assertJsonPath('data.expired', false)
        ->assertJsonMissingPath('data.token_hash');

    $invitation = Invitation::withoutTenancy()->where('email', 'luis@example.com')->sole();

    expect($invitation->company_id)->toBe($this->company->id)
        ->and($invitation->invited_by)->toBe($this->admin->id)
        ->and(AuditLog::withoutTenancy()->where('action', 'invitation.created')->count())->toBe(1);

    Notification::assertSentOnDemand(InvitationNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'luis@example.com');
});

it('puede invitar a otro administrador', function () {
    ($this->asAdmin)()->postJson('/api/v1/invitations', ['email' => 'otra@example.com', 'role' => 'company_admin'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'company_admin');
});

it('HU-3.4 rechaza un correo con cuenta sin revelar a qué empresa pertenece', function () {
    $otra = Company::factory()->create(['razon_social' => 'Empresa Secreta S.A.C.']);
    User::factory()->forCompany($otra)->create(['email' => 'luis@example.com']);

    $response = ($this->asAdmin)()->postJson('/api/v1/invitations', ['email' => 'luis@example.com', 'role' => 'seller'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Este correo no está disponible.');

    expect($response->getContent())->not->toContain('Empresa Secreta');
});

it('rechaza una segunda invitación pendiente al mismo correo', function () {
    Invitation::factory()->create(['company_id' => $this->company->id, 'email' => 'luis@example.com']);

    ($this->asAdmin)()->postJson('/api/v1/invitations', ['email' => 'luis@example.com', 'role' => 'seller'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('valida correo y rol', function () {
    ($this->asAdmin)()->postJson('/api/v1/invitations', ['email' => 'no-es-correo', 'role' => 'jefe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'role']);
});

it('lista las invitaciones pendientes de su empresa', function () {
    Invitation::factory()->create(['company_id' => $this->company->id, 'email' => 'pendiente@example.com']);
    Invitation::factory()->expired()->create(['company_id' => $this->company->id, 'email' => 'vencida@example.com']);
    Invitation::factory()->accepted()->create(['company_id' => $this->company->id, 'email' => 'aceptada@example.com']);
    Invitation::factory()->create(['company_id' => Company::factory()->create()->id, 'email' => 'ajena@example.com']);

    $response = ($this->asAdmin)()->getJson('/api/v1/invitations')->assertOk();

    expect(collect($response->json('data'))->pluck('email')->sort()->values()->all())
        ->toBe(['pendiente@example.com', 'vencida@example.com'])
        ->and($response->json('meta.total'))->toBe(2);
});

it('reenviar genera un token nuevo, invalida el anterior y amplía la vigencia', function () {
    $old = str_repeat('x', 64);
    $invitation = Invitation::factory()->withToken($old)->create([
        'company_id' => $this->company->id,
        'expires_at' => now()->addHour(),
    ]);

    ($this->asAdmin)()->postJson("/api/v1/invitations/{$invitation->id}/resend")->assertOk();

    $fresh = $invitation->fresh();

    expect($fresh->token_hash)->not->toBe(Invitation::hashToken($old))
        ->and($fresh->expires_at->greaterThan(now()->addHours(71)))->toBeTrue()
        ->and(AuditLog::withoutTenancy()->where('action', 'invitation.resent')->count())->toBe(1);

    Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);
    $this->getJson("/api/v1/invitations/{$old}")->assertNotFound();
});

it('cancela una invitación pendiente', function () {
    $invitation = Invitation::factory()->create(['company_id' => $this->company->id]);

    ($this->asAdmin)()->deleteJson("/api/v1/invitations/{$invitation->id}")->assertOk();

    expect(Invitation::withoutTenancy()->find($invitation->id))->toBeNull()
        ->and(AuditLog::withoutTenancy()->where('action', 'invitation.cancelled')->count())->toBe(1);
});

it('no reenvía ni cancela una invitación ya aceptada', function () {
    $invitation = Invitation::factory()->accepted()->create(['company_id' => $this->company->id]);

    ($this->asAdmin)()->postJson("/api/v1/invitations/{$invitation->id}/resend")->assertUnprocessable();
    ($this->asAdmin)()->deleteJson("/api/v1/invitations/{$invitation->id}")->assertUnprocessable();
});

it('responde 404 a una invitación de otra empresa', function () {
    $ajena = Invitation::factory()->create(['company_id' => Company::factory()->create()->id]);

    ($this->asAdmin)()->postJson("/api/v1/invitations/{$ajena->id}/resend")->assertNotFound();
});

it('HU-3.2 el vendedor no gestiona invitaciones', function () {
    $asSeller = $this->withToken($this->seller->createToken('t')->plainTextToken);

    $asSeller->getJson('/api/v1/invitations')->assertForbidden();
    $asSeller->postJson('/api/v1/invitations', ['email' => 'luis@example.com', 'role' => 'seller'])->assertForbidden();
});

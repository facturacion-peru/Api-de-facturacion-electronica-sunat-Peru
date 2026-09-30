<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * T035 · HU-2.3 Reenviar la invitación del administrador de la empresa.
 */

beforeEach(function () {
    Notification::fake();
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->oldToken = Str::random(64);
    $this->invitation = Invitation::factory()->create([
        'company_id' => $this->company->id, 'email' => 'jefe@empresa.pe', 'role' => CompanyRole::CompanyAdmin,
        'token_hash' => Invitation::hashToken($this->oldToken), 'expires_at' => now()->subHour(),
    ]);
    $this->root = User::factory()->platformAdmin()->create();
    $this->resend = function () {
        app('auth')->forgetGuards();

        return $this->withToken($this->root->createToken('t')->plainTextToken)
            ->postJson("/api/v1/platform/companies/{$this->company->id}/admin-invitation/resend");
    };
});

it('envía un enlace nuevo y el anterior deja de valer', function () {
    ($this->resend)()->assertOk()->assertJsonPath('data.admin.invitation', 'pending');

    Notification::assertSentOnDemand(InvitationNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'jefe@empresa.pe'
        && Invitation::hashToken($n->token) === $this->invitation->fresh()->token_hash);
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/invitations/{$this->oldToken}")->assertStatus(404);

    $log = AuditLog::withoutTenancy()->where('action', 'invitation.resent')->sole();
    expect($log->actor_id)->toBe($this->root->id)->and($log->company_id)->toBe($this->company->id);
});

it('422 si el administrador ya aceptó', function () {
    $this->invitation->forceFill(['accepted_at' => now()])->saveQuietly();
    User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();

    ($this->resend)()->assertStatus(422)->assertJsonPath('errors.invitation.0', 'El administrador de esta empresa ya aceptó la invitación.');
    Notification::assertNothingSent();
});

it('422 si no hay invitación de administrador', function () {
    Invitation::withoutTenancy()->whereKey($this->invitation->id)->delete();

    ($this->resend)()->assertStatus(422)->assertJsonPath('errors.invitation.0', 'Esta empresa no tiene una invitación de administrador pendiente.');
});

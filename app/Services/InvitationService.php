<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Emisión de invitaciones: un solo uso, 72 h y solo el hash guardado (RF-010). */
class InvitationService
{
    public const VALID_HOURS = 72;

    public function __construct(private AuditLogger $audit) {}

    public function issue(Company $company, string $email, CompanyRole $role, User $inviter): IssuedInvitation
    {
        $token = Str::random(64);

        $invitation = Invitation::create([
            'company_id' => $company->id,
            'email' => User::normalizeEmail($email),
            'role' => $role,
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addHours(self::VALID_HOURS),
            'invited_by' => $inviter->id,
        ]);

        $this->audit->record('invitation.created', $invitation, [
            'email' => $invitation->email,
            'role' => $role->value,
        ], company: $company, actor: $inviter);

        // El correo sale solo si la transacción que crea la invitación se confirma.
        DB::afterCommit(fn () => Notification::route('mail', $invitation->email)
            ->notify(new InvitationNotification($token, $company, $role, $invitation->expires_at)));

        return new IssuedInvitation($invitation, $token);
    }
}

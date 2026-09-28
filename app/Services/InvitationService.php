<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Emisión de invitaciones: un solo uso, 72 h y solo el hash guardado (RF-010). */
class InvitationService
{
    public const VALID_HOURS = 72;

    public const NOT_VALID = 'La invitación ya no es válida. Pide una nueva.';

    public function __construct(private AuditLogger $audit) {}

    /** Busca por token (sin scope: el invitado aún no tiene empresa). */
    public function findByToken(string $token): ?Invitation
    {
        return Invitation::withoutTenancy()
            ->with('company')
            ->where('token_hash', Invitation::hashToken($token))
            ->first();
    }

    /** 410 si venció, ya se usó o la empresa está desactivada (HU-2.2). */
    public function ensureUsable(Invitation $invitation): void
    {
        if (! $invitation->isPending() || ! $invitation->company->active) {
            throw new HttpException(410, self::NOT_VALID);
        }
    }

    /**
     * Acepta la invitación: crea el usuario y su pertenencia (HU-2.1).
     * La fila se bloquea para que dos aceptaciones simultáneas no creen dos cuentas.
     */
    public function accept(Invitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $invitation = Invitation::withoutTenancy()->with('company')->lockForUpdate()->findOrFail($invitation->id);
            $this->ensureUsable($invitation);

            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Este correo ya tiene una cuenta.']);
            }

            $user = User::create(['name' => $name, 'email' => $invitation->email, 'password' => $password]);

            CompanyMembership::create([
                'company_id' => $invitation->company_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record('invitation.accepted', $invitation, [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
            ], company: $invitation->company, actor: $user);

            return $user;
        });
    }

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

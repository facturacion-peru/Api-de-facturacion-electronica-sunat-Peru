<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Gestión de usuarios de la propia empresa (HU-3). */
class UserService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Cambia rol y/o estado. Nunca deja la empresa sin administrador activo
     * (RF-015): los administradores se bloquean para que dos cambios
     * simultáneos no dejen a la empresa sin ninguno.
     *
     * @param  array{role?: string, active?: bool}  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $membership = CompanyMembership::whereKey($user->membership->id)->lockForUpdate()->firstOrFail();

            $newRole = isset($data['role']) ? CompanyRole::from($data['role']) : $membership->role;
            $newActive = $data['active'] ?? $membership->active;

            $losesAdmin = $membership->role === CompanyRole::CompanyAdmin && $membership->active
                && ($newRole !== CompanyRole::CompanyAdmin || ! $newActive);

            if ($losesAdmin && $this->otherActiveAdmins($membership) === 0) {
                throw ValidationException::withMessages(['user' => 'La empresa debe tener al menos un administrador activo.']);
            }

            if ($newRole !== $membership->role) {
                $this->audit->record('user.role_changed', $user, [
                    'from' => $membership->role->value,
                    'to' => $newRole->value,
                ], actor: $actor);
            }

            if ($newActive !== $membership->active) {
                $this->audit->record($newActive ? 'user.activated' : 'user.deactivated', $user, actor: $actor);

                if (! $newActive) {
                    $user->tokens()->delete();
                }
            }

            $membership->update(['role' => $newRole, 'active' => $newActive]);

            return $user->setRelation('membership', $membership);
        });
    }

    private function otherActiveAdmins(CompanyMembership $membership): int
    {
        return CompanyMembership::query()
            ->where('role', CompanyRole::CompanyAdmin)
            ->where('active', true)
            ->whereKeyNot($membership->id)
            ->lockForUpdate()
            ->count();
    }
}

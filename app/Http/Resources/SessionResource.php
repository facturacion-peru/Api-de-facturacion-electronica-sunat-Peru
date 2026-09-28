<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Sesión de un usuario de empresa: quién es, su empresa y su rol. Incluye el
 * token solo al iniciar sesión o aceptar una invitación.
 *
 * @mixin User
 */
class SessionResource extends ApiResource
{
    public function __construct(User $user, private ?string $token = null)
    {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $membership = $this->membership;
        $company = $membership?->company;

        return [
            'token' => $this->when($this->token !== null, $this->token),
            'expires_at' => $this->when(
                $this->token !== null,
                fn () => now()->addMinutes((int) config('sanctum.expiration'))->toIso8601String(),
            ),
            'user' => UserResource::make($this->resource),
            'company' => $company ? [
                'id' => $company->id,
                'ruc' => $company->ruc,
                'razon_social' => $company->razon_social,
                'nombre_comercial' => $company->nombre_comercial,
            ] : null,
            'role' => $membership?->role->value,
        ];
    }
}

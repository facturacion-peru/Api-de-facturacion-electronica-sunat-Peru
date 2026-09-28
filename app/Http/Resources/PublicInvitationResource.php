<?php

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;

/**
 * Lo mínimo para mostrar la pantalla de aceptar invitación.
 *
 * @mixin Invitation
 */
class PublicInvitationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'company' => [
                'razon_social' => $this->company->razon_social,
                'nombre_comercial' => $this->company->nombre_comercial,
            ],
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Usuario visto desde su empresa: rol y estado vienen de la pertenencia.
 *
 * @mixin User
 */
class CompanyUserResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->membership->role->value,
            'active' => $this->membership->active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}

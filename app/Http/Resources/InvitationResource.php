<?php

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;

/** @mixin Invitation */
class InvitationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'expires_at' => $this->expires_at->toIso8601String(),
            'expired' => $this->expires_at->isPast(),
            'invited_by' => $this->inviter ? ['id' => $this->inviter->id, 'name' => $this->inviter->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

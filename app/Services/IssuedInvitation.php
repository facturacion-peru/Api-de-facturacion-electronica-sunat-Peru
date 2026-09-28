<?php

namespace App\Services;

use App\Models\Invitation;

/** Invitación recién emitida, con su token en claro (no se guarda en la base). */
final readonly class IssuedInvitation
{
    public function __construct(
        public Invitation $invitation,
        public string $token,
    ) {}

    public function url(): string
    {
        return config('app.frontend_url').'/invitacion/'.$this->token;
    }
}

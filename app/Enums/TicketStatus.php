<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Issued = 'issued';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Emitido',
            self::Voided => 'Anulado',
        };
    }
}

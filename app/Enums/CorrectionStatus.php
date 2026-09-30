<?php

namespace App\Enums;

/** Estado de corrección de una factura o boleta según sus notas aceptadas (spec 007). */
enum CorrectionStatus: string
{
    case None = 'none';
    case PartiallyReturned = 'partially_returned';
    case FullyReturned = 'fully_returned';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Vigente',
            self::PartiallyReturned => 'Devuelto parcialmente',
            self::FullyReturned => 'Devuelto totalmente',
            self::Voided => 'Anulado',
        };
    }
}

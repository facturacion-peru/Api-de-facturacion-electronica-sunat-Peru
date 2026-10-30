<?php

namespace App\DataTransfer;

use App\Models\Establishment;

/** Columna «local» de las exportaciones (spec 017): código de anexo y nombre. */
final class EstablishmentLabel
{
    public static function of(?Establishment $establishment): ?string
    {
        return $establishment ? "{$establishment->code} {$establishment->name}" : null;
    }
}

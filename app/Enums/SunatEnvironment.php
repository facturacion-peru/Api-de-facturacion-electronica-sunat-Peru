<?php

namespace App\Enums;

/** Ambientes SUNAT. En el MVP solo beta (A-20); producción llegará con su spec. */
enum SunatEnvironment: string
{
    case Beta = 'beta';

    public function label(): string
    {
        return match ($this) {
            self::Beta => 'Pruebas (beta) — sin valor legal',
        };
    }
}

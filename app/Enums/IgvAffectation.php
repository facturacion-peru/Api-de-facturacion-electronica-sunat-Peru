<?php

namespace App\Enums;

/** Catálogo 07 de SUNAT, operaciones onerosas del MVP (A-22). */
enum IgvAffectation: string
{
    case Gravado = '10';
    case Exonerado = '20';
    case Inafecto = '30';

    public function label(): string
    {
        return match ($this) {
            self::Gravado => 'Gravado (IGV)',
            self::Exonerado => 'Exonerado',
            self::Inafecto => 'Inafecto',
        };
    }
}

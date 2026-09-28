<?php

namespace App\Enums;

enum PersonType: string
{
    case Natural = 'natural';
    case Juridica = 'juridica';

    public function label(): string
    {
        return match ($this) {
            self::Natural => 'Persona natural',
            self::Juridica => 'Persona jurídica',
        };
    }
}

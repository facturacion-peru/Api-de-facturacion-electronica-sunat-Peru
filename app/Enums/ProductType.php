<?php

namespace App\Enums;

enum ProductType: string
{
    case Good = 'good';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Bien',
            self::Service => 'Servicio',
        };
    }

    /** Los servicios no tienen stock ni lotes (A-16). */
    public function tracksStock(): bool
    {
        return $this === self::Good;
    }
}

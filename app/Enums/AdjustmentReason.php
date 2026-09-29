<?php

namespace App\Enums;

/** Motivos de ajuste y reversión (HU-4). */
enum AdjustmentReason: string
{
    case Count = 'count';
    case Shrinkage = 'shrinkage';
    case Damage = 'damage';
    case Expiry = 'expiry';
    case Error = 'error';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Conteo físico',
            self::Shrinkage => 'Merma',
            self::Damage => 'Daño',
            self::Expiry => 'Vencimiento',
            self::Error => 'Error de registro',
            self::Other => 'Otro',
        };
    }
}

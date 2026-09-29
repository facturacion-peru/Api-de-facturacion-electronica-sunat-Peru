<?php

namespace App\Enums;

/** Estados de la configuración SUNAT de una empresa (RF-010). */
enum SunatStatus: string
{
    case NotConfigured = 'not_configured';
    case Pending = 'pending';
    case Validated = 'validated';
    case Error = 'error';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'No configurada',
            self::Pending => 'Pendiente de validación',
            self::Validated => 'Validada',
            self::Error => 'Con error',
            self::Inactive => 'Inactiva',
        };
    }
}

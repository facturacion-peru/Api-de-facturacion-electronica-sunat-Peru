<?php

namespace App\Enums;

/** Medios de pago del MVP (A-19, RF-013). */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case YapePlin = 'yape_plin';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
            self::YapePlin => 'Yape / Plin',
            self::Transfer => 'Transferencia',
        };
    }
}

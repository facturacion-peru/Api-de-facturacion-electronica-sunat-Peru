<?php

namespace App\Enums;

/** Tipos de movimiento de inventario (RF-012). */
enum MovementType: string
{
    case Entry = 'entry';
    case Sale = 'sale';
    case Adjustment = 'adjustment';
    case Return = 'return';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::Sale => 'Venta',
            self::Adjustment => 'Ajuste',
            self::Return => 'Devolución',
            self::Reversal => 'Reversión',
        };
    }
}

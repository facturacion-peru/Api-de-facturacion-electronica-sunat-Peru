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
    // Traslado entre locales (spec 017): salida del origen y entrada al destino.
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::Sale => 'Venta',
            self::Adjustment => 'Ajuste',
            self::Return => 'Devolución',
            self::Reversal => 'Reversión',
            self::TransferOut => 'Traslado (salida)',
            self::TransferIn => 'Traslado (entrada)',
        };
    }

    public function isTransfer(): bool
    {
        return $this === self::TransferOut || $this === self::TransferIn;
    }
}

<?php

namespace App\Enums;

/** Motivos de nota de crédito del MVP (catálogo 09 de SUNAT, A-39 ⚖️). */
enum CreditNoteReason: string
{
    case Voiding = '01';
    case TotalReturn = '06';
    case ItemReturn = '07';

    public function label(): string
    {
        return match ($this) {
            self::Voiding => 'Anulación de la operación',
            self::TotalReturn => 'Devolución total',
            self::ItemReturn => 'Devolución por ítem',
        };
    }

    /** En una devolución el stock vuelve siempre; en la anulación se decide (A-40). */
    public function alwaysRestocks(): bool
    {
        return $this !== self::Voiding;
    }

    /** Anulación y devolución total cubren todo lo que queda por devolver. */
    public function coversRemainder(): bool
    {
        return $this !== self::ItemReturn;
    }
}

<?php

namespace App\Enums;

/**
 * Régimen tributario de la empresa. Condiciona qué comprobantes puede emitir
 * (A-11, a confirmar con un contador antes de producción).
 */
enum TaxRegime: string
{
    case Nrus = 'nrus';
    case Rer = 'rer';
    case Rmt = 'rmt';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Nrus => 'Nuevo RUS',
            self::Rer => 'Régimen Especial (RER)',
            self::Rmt => 'Régimen MYPE Tributario',
            self::General => 'Régimen General',
        };
    }

    /** El Nuevo RUS solo emite boletas, no facturas. */
    public function canIssueInvoices(): bool
    {
        return $this !== self::Nrus;
    }
}

<?php

namespace App\Enums;

/** Comprobantes del MVP (catálogo 01 de SUNAT, A-22). */
enum DocumentType: string
{
    case Invoice = '01';
    case Receipt = '03';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factura',
            self::Receipt => 'Boleta de venta',
        };
    }

    /** Las series de factura empiezan por F y las de boleta por B. */
    public function seriesPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'F',
            self::Receipt => 'B',
        };
    }

    /** 4 caracteres: prefijo + 3 alfanuméricos (F001, B0A1…). */
    public function seriesPattern(): string
    {
        return '/^'.$this->seriesPrefix().'[A-Z0-9]{3}$/';
    }
}

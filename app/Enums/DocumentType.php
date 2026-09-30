<?php

namespace App\Enums;

/** Comprobantes del MVP (catálogo 01 de SUNAT, A-22) y la nota de crédito (spec 007, A-39). */
enum DocumentType: string
{
    case Invoice = '01';
    case Receipt = '03';
    case CreditNote = '07';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factura',
            self::Receipt => 'Boleta de venta',
            self::CreditNote => 'Nota de crédito',
        };
    }

    /** Factura o boleta: lo que se emite desde una venta y admite notas. */
    public function isSale(): bool
    {
        return $this !== self::CreditNote;
    }

    /**
     * Las series de factura empiezan por F y las de boleta por B. La nota de
     * crédito lleva la letra del comprobante que modifica (FC01, BC01).
     */
    public function seriesPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'F',
            self::Receipt => 'B',
            self::CreditNote => 'F o B',
        };
    }

    /** 4 caracteres: prefijo + 3 alfanuméricos (F001, B0A1, FC01…). */
    public function seriesPattern(): string
    {
        return match ($this) {
            self::CreditNote => '/^[FB][A-Z0-9]{3}$/',
            default => '/^'.$this->seriesPrefix().'[A-Z0-9]{3}$/',
        };
    }
}

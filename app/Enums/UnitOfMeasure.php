<?php

namespace App\Enums;

/**
 * Subconjunto del catálogo 03 de SUNAT (unidades de medida, UN/ECE rec. 20).
 * Una unidad por producto, sin conversiones (A-16).
 */
enum UnitOfMeasure: string
{
    case Unit = 'NIU';
    case Service = 'ZZ';
    case Kilogram = 'KGM';
    case Gram = 'GRM';
    case Liter = 'LTR';
    case Milliliter = 'MLT';
    case Meter = 'MTR';
    case Box = 'BX';
    case Pack = 'PK';
    case Dozen = 'DZN';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unidad',
            self::Service => 'Servicio',
            self::Kilogram => 'Kilogramo',
            self::Gram => 'Gramo',
            self::Liter => 'Litro',
            self::Milliliter => 'Mililitro',
            self::Meter => 'Metro',
            self::Box => 'Caja',
            self::Pack => 'Paquete',
            self::Dozen => 'Docena',
        };
    }

    /** Peso, volumen y longitud admiten fracciones; lo contable, no. */
    public function allowsDecimals(): bool
    {
        return in_array($this, [self::Kilogram, self::Gram, self::Liter, self::Milliliter, self::Meter], true);
    }
}

<?php

namespace App\Enums;

/** Documentos de identidad del cliente, con el código del catálogo 06 de SUNAT (RF-030). */
enum CustomerDocumentType: string
{
    case Dni = '1';
    case ForeignerCard = '4';
    case Ruc = '6';

    public function label(): string
    {
        return match ($this) {
            self::Dni => 'DNI',
            self::ForeignerCard => 'Carné de extranjería',
            self::Ruc => 'RUC',
        };
    }

    /** Formato del número; el dígito verificador del RUC lo comprueba la regla Ruc. */
    public function pattern(): string
    {
        return match ($this) {
            self::Dni => '/^\d{8}$/',
            self::ForeignerCard => '/^[A-Z0-9]{8,12}$/',
            self::Ruc => '/^\d{11}$/',
        };
    }

    public function formatHint(): string
    {
        return match ($this) {
            self::Dni => 'El DNI debe tener 8 dígitos.',
            self::ForeignerCard => 'El carné de extranjería debe tener de 8 a 12 letras o números.',
            self::Ruc => 'El RUC debe tener exactamente 11 dígitos.',
        };
    }
}

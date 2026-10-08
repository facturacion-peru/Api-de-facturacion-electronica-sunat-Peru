<?php

namespace App\DataTransfer;

use App\Enums\CustomerDocumentType;
use App\Models\Customer;

/** Columnas de clientes, comunes a la plantilla, la exportación y la importación (spec 014). */
final class CustomerColumns
{
    public const ALL = [
        'tipo_documento' => Spreadsheet::TEXT,
        'numero_documento' => Spreadsheet::TEXT,
        'nombre' => Spreadsheet::TEXT,
        'direccion' => Spreadsheet::TEXT,
    ];

    public const REQUIRED = ['tipo_documento', 'numero_documento', 'nombre'];

    public const TEXT_IN_TEMPLATE = ['numero_documento'];

    /** Sigla que se escribe; al importar también se acepta el código SUNAT (1, 4, 6). */
    public const DOCUMENT_TYPES = ['1' => 'DNI', '4' => 'CE', '6' => 'RUC'];

    /** @return list<string|null> */
    public static function values(Customer $customer): array
    {
        return [
            self::DOCUMENT_TYPES[$customer->document_type->value],
            $customer->document_number,
            $customer->name,
            $customer->address,
        ];
    }

    public static function documentType(string $value): ?CustomerDocumentType
    {
        $value = mb_strtoupper(trim($value));
        $code = array_search($value, self::DOCUMENT_TYPES, true);

        return CustomerDocumentType::tryFrom($code === false ? $value : (string) $code);
    }
}

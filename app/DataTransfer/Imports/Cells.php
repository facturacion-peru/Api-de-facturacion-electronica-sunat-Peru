<?php

namespace App\DataTransfer\Imports;

/** Conversión de los textos de una celda a los valores que valida el alta manual. */
final class Cells
{
    private const YES = ['si', 'sí', 's', '1', 'true', 'verdadero', 'x'];

    private const NO = ['no', 'n', '0', 'false', 'falso'];

    /** «3,80» → «3.80»; vacío → null. Lo demás queda igual para que la validación lo rechace. */
    public static function decimal(string $value): ?string
    {
        $value = str_replace(' ', '', $value);
        if ($value === '') {
            return null;
        }

        return str_contains($value, ',') && ! str_contains($value, '.') ? str_replace(',', '.', $value) : $value;
    }

    /** si/no → bool; vacío → $default; lo demás queda como texto (la regla boolean lo rechaza). */
    public static function boolean(string $value, ?bool $default): bool|string|null
    {
        $normalized = mb_strtolower(trim($value));

        return match (true) {
            $normalized === '' => $default,
            in_array($normalized, self::YES, true) => true,
            in_array($normalized, self::NO, true) => false,
            default => $value,
        };
    }

    /** AAAA-MM-DD o DD/MM/AAAA → AAAA-MM-DD; vacío → null. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return $value;
    }

    public static function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

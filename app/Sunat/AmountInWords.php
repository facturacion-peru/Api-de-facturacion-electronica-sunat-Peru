<?php

namespace App\Sunat;

/** Importe en letras para la leyenda 1000 del comprobante: «SON … CON 45/100 SOLES». */
final class AmountInWords
{
    private const UNITS = [
        'cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];

    private const TENS = [3 => 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];

    private const HUNDREDS = [1 => 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    /** @param  string  $amount  cadena decimal con 2 decimales (p. ej. «77.30») */
    public static function soles(string $amount): string
    {
        [$integer, $cents] = explode('.', bcadd($amount, '0', 2));

        return mb_strtoupper('son '.self::integer((int) $integer)." con {$cents}/100 soles");
    }

    private static function integer(int $n): string
    {
        if ($n === 0) {
            return 'cero';
        }

        $millions = intdiv($n, 1_000_000);
        $thousands = intdiv($n % 1_000_000, 1000);
        $rest = $n % 1000;
        $parts = [];

        if ($millions > 0) {
            $parts[] = $millions === 1 ? 'un millón' : self::apocope(self::upTo999($millions)).' millones';
        }
        if ($thousands > 0) {
            $parts[] = $thousands === 1 ? 'mil' : self::apocope(self::upTo999($thousands)).' mil';
        }
        if ($rest > 0) {
            $parts[] = self::upTo999($rest);
        }

        return implode(' ', $parts);
    }

    private static function upTo999(int $n): string
    {
        if ($n === 100) {
            return 'cien';
        }

        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        $words = $hundreds > 0 ? [self::HUNDREDS[$hundreds]] : [];

        if ($rest > 0) {
            $words[] = $rest < 30
                ? self::UNITS[$rest]
                : self::TENS[intdiv($rest, 10)].($rest % 10 > 0 ? ' y '.self::UNITS[$rest % 10] : '');
        }

        return implode(' ', $words);
    }

    /** «uno» se apocopa ante mil y millones: veintiún mil, treinta y un millones. */
    private static function apocope(string $words): string
    {
        return preg_replace(['/veintiuno$/u', '/\buno$/u'], ['veintiún', 'un'], $words);
    }
}

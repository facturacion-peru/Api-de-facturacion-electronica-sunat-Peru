<?php

namespace App\Support;

/**
 * Decimales exactos con bcmath (plan 002).
 *
 * PostgreSQL devuelve los `numeric` como cadenas exactas; SQLite (la suite
 * rápida) los guarda como flotantes y SUM() acumula error. fromDb() redondea
 * a la escala en vez de truncar, para que ambos den el mismo resultado.
 */
final class Decimal
{
    public static function fromDb(mixed $value, int $scale = 3): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', $scale);
        }

        if (is_float($value) || (is_string($value) && stripos($value, 'e') !== false)) {
            $value = number_format(round((float) $value, $scale), $scale, '.', '');
        }

        // bcadd trunca: se suma medio ulp en la dirección del signo para redondear.
        $half = '0.'.str_repeat('0', $scale).'5';
        $value = (string) $value;

        return bcadd(str_starts_with($value, '-') ? bcsub($value, $half, $scale + 1) : bcadd($value, $half, $scale + 1), '0', $scale);
    }

    /** Redondeo mitad hacia arriba, lejos de cero (2.345 → 2.35; -2.345 → -2.35). */
    public static function round(string $value, int $scale = 2): string
    {
        return self::fromDb($value, $scale);
    }

    /** Producto exacto redondeado a la escala (importe = cantidad × precio). */
    public static function mul(string $a, string $b, int $scale = 2): string
    {
        return self::round(bcmul($a, $b, $scale + 6), $scale);
    }

    /** @param  iterable<mixed>  $values */
    public static function sum(iterable $values, int $scale = 3): string
    {
        $total = bcadd('0', '0', $scale);

        foreach ($values as $value) {
            $total = bcadd($total, self::fromDb($value, $scale), $scale);
        }

        return $total;
    }
}

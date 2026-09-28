<?php

namespace App\Rules;

use App\Enums\PersonType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RUC peruano (RF-002): 11 dígitos, prefijo 10 (persona natural) o 20
 * (persona jurídica) y dígito verificador módulo 11. Otros prefijos (15, 17)
 * quedan fuera del MVP (A-11).
 */
class Ruc implements ValidationRule
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{11}$/', $value) !== 1) {
            $fail('El RUC debe tener exactamente 11 dígitos.');

            return;
        }

        if (! in_array(substr($value, 0, 2), ['10', '20'], true)) {
            $fail('Solo se admiten RUC de persona natural (10) o persona jurídica (20).');

            return;
        }

        if ((int) $value[10] !== self::checkDigit(substr($value, 0, 10))) {
            $fail('El RUC no es válido: el dígito verificador no coincide.');
        }
    }

    public static function checkDigit(string $base): int
    {
        $sum = 0;

        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * self::WEIGHTS[$i];
        }

        return match ($check = 11 - ($sum % 11)) {
            10 => 0,
            11 => 1,
            default => $check,
        };
    }

    /** Solo para RUC ya validados. */
    public static function personType(string $ruc): PersonType
    {
        return str_starts_with($ruc, '10') ? PersonType::Natural : PersonType::Juridica;
    }
}

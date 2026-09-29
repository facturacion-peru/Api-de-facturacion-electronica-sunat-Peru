<?php

namespace App\Rules;

use App\Enums\UnitOfMeasure;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rechaza fracciones si la unidad no las admite (NIU, caja, docena…). */
class QuantityForUnit implements ValidationRule
{
    public function __construct(private ?UnitOfMeasure $unit) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $this->unit === null || $this->unit->allowsDecimals() || ! is_numeric($value)) {
            return;
        }

        if (bccomp(bcsub((string) $value, bcadd((string) $value, '0', 0), 3), '0', 3) !== 0) {
            $fail('Esta unidad de medida no admite decimales.');
        }
    }
}

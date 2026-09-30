<?php

namespace App\Sunat;

use App\Enums\IgvAffectation;
use App\Support\Decimal;
use InvalidArgumentException;

/**
 * Importes de una línea de nota de crédito (spec 007, plan «Cálculo de la
 * nota»). Mismo precio que la línea original; el descuento se prorratea y la
 * devolución que agota la línea toma el resto exacto del bruto y del
 * descuento, así la suma de todas las notas cuadra al céntimo (CE-002).
 * Regla aceptada por SUNAT beta en el spike (T002).
 */
final class CreditNoteCalculator
{
    public function __construct(private TaxCalculator $calculator) {}

    public function line(
        string $originalQuantity,
        string $unitPrice,
        string $originalDiscount,
        IgvAffectation $affectation,
        string $quantity,
        ReturnedSoFar $sofar,
    ): CalculatedLine {
        $remaining = bcsub(bcadd($originalQuantity, '0', 3), $sofar->quantity, 3);
        $quantity = bcadd($quantity, '0', 3);

        if (bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, $remaining, 3) > 0) {
            throw new InvalidArgumentException("Solo quedan {$remaining} por devolver.");
        }

        if (bccomp($quantity, $remaining, 3) === 0) {
            $gross = bcsub(Decimal::mul($originalQuantity, $unitPrice), $sofar->gross, 2);
            $discount = bcsub(Decimal::round($originalDiscount), $sofar->discount, 2);
        } else {
            $gross = Decimal::mul($quantity, $unitPrice);
            $discount = Decimal::round(bcdiv(bcmul($originalDiscount, $quantity, 8), $originalQuantity, 8));
        }

        return $this->calculator->lineFromGross($unitPrice, $gross, $discount, $affectation);
    }
}

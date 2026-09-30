<?php

namespace App\Sunat;

use App\Enums\IgvAffectation;
use App\Support\Decimal;

/**
 * Importes de un comprobante (spec 005, HU-3). Puro y determinista.
 *
 * El precio del catálogo incluye IGV (A-16) y el importe de la línea es el
 * mismo que en el ticket: redondeo(cantidad × precio, 2) − descuento. De ahí
 * se desglosa la base: importe / 1.18 si es gravado; el IGV es la diferencia,
 * así base + IGV = importe siempre. SUNAT beta aceptó esta regla en el spike
 * (T002), incluidos descuentos y redondeos límite.
 */
final class TaxCalculator
{
    public const IGV_RATE = '18';

    private const IGV_FACTOR = '1.18';

    public function line(string $quantity, string $unitPrice, string $discount, IgvAffectation $affectation): CalculatedLine
    {
        return $this->lineFromGross($unitPrice, Decimal::mul($quantity, $unitPrice), $discount, $affectation);
    }

    /**
     * Igual que line(), con el bruto ya dado: las notas de crédito lo usan
     * para que la última devolución tome el resto exacto (spec 007).
     */
    public function lineFromGross(string $unitPrice, string $gross, string $discount, IgvAffectation $affectation): CalculatedLine
    {
        $discount = Decimal::round($discount !== '' ? $discount : '0');
        $amount = bcsub($gross, $discount, 2);

        if ($affectation === IgvAffectation::Gravado) {
            $base = $this->withoutIgv($amount);
            $grossBase = $this->withoutIgv($gross);
            $unitValue = Decimal::round(bcdiv($unitPrice, self::IGV_FACTOR, 14), 10);
        } else {
            $base = $amount;
            $grossBase = $gross;
            $unitValue = Decimal::round($unitPrice, 10);
        }

        return new CalculatedLine(
            affectation: $affectation,
            grossAmount: $gross,
            discount: $discount,
            amount: $amount,
            baseAmount: $base,
            igv: bcsub($amount, $base, 2),
            unitValue: $unitValue,
            grossBase: $grossBase,
            // Descuento de línea por su valor sin IGV (código 00, afecta la base).
            discountBase: bcsub($grossBase, $base, 2),
        );
    }

    /** @param  list<CalculatedLine>  $lines */
    public function totals(array $lines): CalculatedTotals
    {
        $base = fn (IgvAffectation $affectation) => Decimal::sum(
            array_map(fn (CalculatedLine $l) => $l->baseAmount, array_filter($lines, fn (CalculatedLine $l) => $l->affectation === $affectation)),
            2,
        );

        return new CalculatedTotals(
            opGravadas: $base(IgvAffectation::Gravado),
            opExoneradas: $base(IgvAffectation::Exonerado),
            opInafectas: $base(IgvAffectation::Inafecto),
            igv: Decimal::sum(array_map(fn (CalculatedLine $l) => $l->igv, $lines), 2),
            discountTotal: Decimal::sum(array_map(fn (CalculatedLine $l) => $l->discount, $lines), 2),
            total: Decimal::sum(array_map(fn (CalculatedLine $l) => $l->amount, $lines), 2),
        );
    }

    private function withoutIgv(string $amount): string
    {
        return Decimal::round(bcdiv($amount, self::IGV_FACTOR, 8));
    }
}

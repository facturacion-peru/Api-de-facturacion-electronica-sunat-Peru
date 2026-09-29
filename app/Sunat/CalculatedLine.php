<?php

namespace App\Sunat;

use App\Enums\IgvAffectation;

/** Importes de una línea de comprobante, en cadenas decimales exactas. */
final readonly class CalculatedLine
{
    public function __construct(
        public IgvAffectation $affectation,
        public string $grossAmount,
        public string $discount,
        public string $amount,
        public string $baseAmount,
        public string $igv,
        public string $unitValue,
        public string $grossBase,
        public string $discountBase,
    ) {}
}

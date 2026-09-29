<?php

namespace App\Sunat;

/** Totales del comprobante por tipo de afectación (HU-3.2). */
final readonly class CalculatedTotals
{
    public function __construct(
        public string $opGravadas,
        public string $opExoneradas,
        public string $opInafectas,
        public string $igv,
        public string $discountTotal,
        public string $total,
    ) {}
}

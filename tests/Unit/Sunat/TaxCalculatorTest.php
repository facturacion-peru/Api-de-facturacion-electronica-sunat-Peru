<?php

use App\Enums\IgvAffectation;
use App\Sunat\TaxCalculator;
use App\Support\Decimal;

/*
 * T030 · HU-3 Cálculo de importes (RF-003, CE-001). Los casos son los que
 * SUNAT beta aceptó en el spike (T002, plan 005).
 */

it('calcula cada línea según la regla del plan', function (string $qty, string $price, string $discount, IgvAffectation $afe, array $expected) {
    $line = (new TaxCalculator)->line($qty, $price, $discount, $afe);

    expect([$line->grossAmount, $line->amount, $line->baseAmount, $line->igv, $line->unitValue, $line->discountBase])->toBe($expected);
})->with([
    'gravado' => ['2', '25.90', '0', IgvAffectation::Gravado, ['51.80', '51.80', '43.90', '7.90', '21.9491525424', '0.00']],
    'exonerado' => ['3', '4.50', '0', IgvAffectation::Exonerado, ['13.50', '13.50', '13.50', '0.00', '4.5000000000', '0.00']],
    'inafecto' => ['1', '12.00', '0', IgvAffectation::Inafecto, ['12.00', '12.00', '12.00', '0.00', '12.0000000000', '0.00']],
    'descuento' => ['4', '7.50', '1.00', IgvAffectation::Gravado, ['30.00', '29.00', '24.58', '4.42', '6.3559322034', '0.84']],
    '3 × 0.33' => ['3', '0.33', '0', IgvAffectation::Gravado, ['0.99', '0.99', '0.84', '0.15', '0.2796610169', '0.00']],
    '7 × 0.10' => ['7', '0.10', '0', IgvAffectation::Gravado, ['0.70', '0.70', '0.59', '0.11', '0.0847457627', '0.00']],
    'por peso' => ['2.500', '4.20', '0', IgvAffectation::Gravado, ['10.50', '10.50', '8.90', '1.60', '3.5593220339', '0.00']],
    'un céntimo' => ['1', '0.01', '0', IgvAffectation::Gravado, ['0.01', '0.01', '0.01', '0.00', '0.0084745763', '0.00']],
    'redondeo del bruto' => ['0.333', '3.00', '0', IgvAffectation::Gravado, ['1.00', '1.00', '0.85', '0.15', '2.5423728814', '0.00']],
    'exacto' => ['1', '1.18', '0', IgvAffectation::Gravado, ['1.18', '1.18', '1.00', '0.18', '1.0000000000', '0.00']],
]);

it('HU-3.2 informa cada total por separado y el total general cuadra', function () {
    $calc = new TaxCalculator;
    $totals = $calc->totals([
        $calc->line('2', '25.90', '0', IgvAffectation::Gravado),
        $calc->line('3', '4.50', '0', IgvAffectation::Exonerado),
        $calc->line('1', '12.00', '0', IgvAffectation::Inafecto),
        $calc->line('4', '7.50', '1.00', IgvAffectation::Gravado),
    ]);

    expect([$totals->opGravadas, $totals->opExoneradas, $totals->opInafectas, $totals->igv, $totals->discountTotal, $totals->total])
        ->toBe(['68.48', '13.50', '12.00', '12.32', '1.00', '106.30'])
        ->and(bcadd(bcadd(bcadd($totals->opGravadas, $totals->opExoneradas, 2), $totals->opInafectas, 2), $totals->igv, 2))->toBe($totals->total);
});

it('HU-3.3 el descuento reduce la base imponible', function () {
    $calc = new TaxCalculator;

    expect($calc->line('4', '7.50', '1.00', IgvAffectation::Gravado)->baseAmount)
        ->toBe('24.58')
        ->and($calc->line('4', '7.50', '0', IgvAffectation::Gravado)->baseAmount)->toBe('25.42');
});

it('HU-3.4 el importe es el mismo que el del ticket y el cálculo es determinista', function () {
    $calc = new TaxCalculator;

    foreach ([['3', '2.99', '0.97'], ['2.500', '4.20', '0'], ['7', '0.10', '0.05']] as [$qty, $price, $discount]) {
        $first = $calc->line($qty, $price, $discount, IgvAffectation::Gravado);

        expect($first->amount)->toBe(bcsub(Decimal::mul($qty, $price), $discount, 2))
            ->and($calc->line($qty, $price, $discount, IgvAffectation::Gravado))->toEqual($first);
    }
});

it('la suma de muchas líneas de céntimos no se descuadra', function () {
    $calc = new TaxCalculator;
    $lines = array_map(fn ($i) => $calc->line('1', '0.0'.(1 + $i % 9), '0', IgvAffectation::Gravado), range(0, 24));
    $totals = $calc->totals($lines);

    expect(bcadd($totals->opGravadas, $totals->igv, 2))->toBe($totals->total)
        ->and($totals->total)->toBe('1.18');
});

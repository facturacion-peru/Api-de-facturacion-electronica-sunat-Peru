<?php

use App\Enums\IgvAffectation;
use App\Sunat\CreditNoteCalculator;
use App\Sunat\ReturnedSoFar;
use App\Sunat\TaxCalculator;

/*
 * T020 · Importes de las líneas de una nota de crédito (plan 007): el
 * descuento se prorratea y la última devolución de una línea toma el resto
 * exacto del bruto y del descuento, para cuadrar al céntimo (CE-002).
 */

function returnLine(array $original, string $quantity, ?ReturnedSoFar $sofar = null)
{
    [$qty, $price, $discount, $afe] = $original;

    return (new CreditNoteCalculator(new TaxCalculator))->line($qty, $price, $discount, $afe, $quantity, $sofar ?? ReturnedSoFar::none());
}

$yogur = ['4', '7.50', '1.00', IgvAffectation::Gravado];

it('devolución parcial con el descuento prorrateado (caso aceptado en el spike)', function () use ($yogur) {
    $line = returnLine($yogur, '1');

    expect([$line->grossAmount, $line->discount, $line->amount, $line->baseAmount, $line->igv])->toBe(['7.50', '0.25', '7.25', '6.14', '1.11']);
});

it('la anulación del resto toma lo que queda exacto (caso aceptado en el spike)', function () use ($yogur) {
    $line = returnLine($yogur, '3', new ReturnedSoFar('1', '7.50', '0.25'));

    expect([$line->grossAmount, $line->discount, $line->amount])->toBe(['22.50', '0.75', '21.75']);
});

it('devoluciones sucesivas suman exacto la línea original', function (array $original, array $parts) {
    [$qty, $price, $discount, $afe] = $original;
    $full = (new TaxCalculator)->line($qty, $price, $discount, $afe);
    $sofar = ReturnedSoFar::none();
    $amounts = [];

    foreach ($parts as $part) {
        $line = returnLine($original, $part, $sofar);
        $amounts[] = $line->amount;
        $sofar = $sofar->plus($part, $line->grossAmount, $line->discount);
    }

    expect($sofar->quantity)->toBe(bcadd($qty, '0', 3))
        ->and($sofar->discount)->toBe($full->discount)
        ->and(bcadd(bcadd($amounts[0], $amounts[1] ?? '0', 2), $amounts[2] ?? '0', 2))->toBe($full->amount);
})->with([
    'descuento que no divide exacto' => [['3', '2.99', '0.97', IgvAffectation::Gravado], ['1', '1', '1']],
    'bruto redondeado (0.333 kg)' => [['0.333', '3.00', '0', IgvAffectation::Gravado], ['0.111', '0.111', '0.111']],
    'por peso' => [['2.500', '4.20', '0.10', IgvAffectation::Gravado], ['1.250', '1.250']],
    'exonerado' => [['3', '4.50', '0', IgvAffectation::Exonerado], ['2', '1']],
    'con el mismo yogur' => [$yogur, ['1', '1', '2']],
]);

it('reparte el descuento sin pasarse en la última', function () {
    $sofar = ReturnedSoFar::none();
    $discounts = [];
    foreach (['1', '1', '1'] as $part) {
        $line = returnLine(['3', '2.99', '0.97', IgvAffectation::Gravado], $part, $sofar);
        $discounts[] = $line->discount;
        $sofar = $sofar->plus($part, $line->grossAmount, $line->discount);
    }

    expect($discounts)->toBe(['0.32', '0.32', '0.33']);
});

it('no permite devolver más de lo que queda', function () use ($yogur) {
    expect(fn () => returnLine($yogur, '2', new ReturnedSoFar('3', '22.50', '0.75')))->toThrow(InvalidArgumentException::class);
});

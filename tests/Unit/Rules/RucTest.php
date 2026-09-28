<?php

use App\Enums\PersonType;
use App\Rules\Ruc;

/*
 * T021 · RUC: 11 dígitos, prefijos 10 y 20, dígito verificador módulo 11
 * (RF-002) y tipo de persona deducido del prefijo (RF-003, A-11).
 */

function rucErrors(mixed $value): array
{
    $errors = [];
    (new Ruc)->validate('ruc', $value, function (string $message) use (&$errors) {
        $errors[] = $message;
    });

    return $errors;
}

it('acepta RUC válidos', function (string $ruc) {
    expect(rucErrors($ruc))->toBe([]);
})->with([
    'SUNAT (persona jurídica)' => '20131312955',
    'dígito 0 (resto 10)' => '20100070970',
    'dígito 1 (resto 11)' => '20100000131',
    'persona natural' => '10468536248',
]);

it('rechaza un dígito verificador incorrecto', function () {
    expect(rucErrors('20131312956'))->toBe(['El RUC no es válido: el dígito verificador no coincide.']);
});

it('rechaza longitudes y caracteres inválidos', function (mixed $ruc) {
    expect(rucErrors($ruc))->toBe(['El RUC debe tener exactamente 11 dígitos.']);
})->with(['2013131295', '201313129551', '2013131295A', ' 20131312955', 20131312955, null]);

it('rechaza prefijos distintos de 10 y 20', function (string $ruc) {
    expect(rucErrors($ruc))->toBe(['Solo se admiten RUC de persona natural (10) o persona jurídica (20).']);
})->with(['15123456789', '17123456789', '30123456789']);

it('deduce el tipo de persona del prefijo', function () {
    expect(Ruc::personType('10468536248'))->toBe(PersonType::Natural)
        ->and(Ruc::personType('20131312955'))->toBe(PersonType::Juridica);
});

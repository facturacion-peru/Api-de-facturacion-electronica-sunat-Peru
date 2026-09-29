<?php

use App\Sunat\AmountInWords;

/*
 * T032 · Leyenda 1000 del comprobante («SON … SOLES»). La versión heredada
 * de legacy/PdfService fallaba con 21–29 («veinte y uno»), con los miles
 * terminados en uno y con millones; esta la reemplaza.
 */

it('escribe el importe en letras con los céntimos', function (string $amount, string $expected) {
    expect(AmountInWords::soles($amount))->toBe($expected);
})->with([
    ['0.00', 'SON CERO CON 00/100 SOLES'],
    ['1.00', 'SON UNO CON 00/100 SOLES'],
    ['0.50', 'SON CERO CON 50/100 SOLES'],
    ['16.00', 'SON DIECISÉIS CON 00/100 SOLES'],
    ['21.00', 'SON VEINTIUNO CON 00/100 SOLES'],
    ['22.10', 'SON VEINTIDÓS CON 10/100 SOLES'],
    ['31.00', 'SON TREINTA Y UNO CON 00/100 SOLES'],
    ['77.30', 'SON SETENTA Y SIETE CON 30/100 SOLES'],
    ['100.00', 'SON CIEN CON 00/100 SOLES'],
    ['101.00', 'SON CIENTO UNO CON 00/100 SOLES'],
    ['500.00', 'SON QUINIENTOS CON 00/100 SOLES'],
    ['777.00', 'SON SETECIENTOS SETENTA Y SIETE CON 00/100 SOLES'],
    ['1000.00', 'SON MIL CON 00/100 SOLES'],
    ['1001.00', 'SON MIL UNO CON 00/100 SOLES'],
    ['2500.99', 'SON DOS MIL QUINIENTOS CON 99/100 SOLES'],
    ['21000.00', 'SON VEINTIÚN MIL CON 00/100 SOLES'],
    ['31000.00', 'SON TREINTA Y UN MIL CON 00/100 SOLES'],
    ['100000.00', 'SON CIEN MIL CON 00/100 SOLES'],
    ['1000000.00', 'SON UN MILLÓN CON 00/100 SOLES'],
    ['1500000.00', 'SON UN MILLÓN QUINIENTOS MIL CON 00/100 SOLES'],
    ['2000001.00', 'SON DOS MILLONES UNO CON 00/100 SOLES'],
    ['21000000.00', 'SON VEINTIÚN MILLONES CON 00/100 SOLES'],
]);

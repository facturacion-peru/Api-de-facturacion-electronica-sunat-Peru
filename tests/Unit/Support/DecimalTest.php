<?php

use App\Support\Decimal;

/*
 * Valores numéricos leídos de la base: PostgreSQL devuelve cadenas exactas;
 * SQLite, flotantes con error acumulado en SUM(). Se redondean, no se truncan.
 */

it('normaliza cadenas exactas a la escala pedida', function () {
    expect(Decimal::fromDb('12.345'))->toBe('12.345')
        ->and(Decimal::fromDb('7'))->toBe('7.000')
        ->and(Decimal::fromDb('-2.5'))->toBe('-2.500');
});

it('redondea el error de coma flotante en lugar de truncarlo', function () {
    expect(Decimal::fromDb(12.344999999999999))->toBe('12.345')
        ->and(Decimal::fromDb(0.1 + 0.2))->toBe('0.300')
        ->and(Decimal::fromDb(0.1 + 0.2 - 0.3))->toBe('0.000');
});

it('trata nulos y notación científica', function () {
    expect(Decimal::fromDb(null))->toBe('0.000')
        ->and(Decimal::fromDb('5.5511151231258E-17'))->toBe('0.000')
        ->and(Decimal::fromDb('1.25', 4))->toBe('1.2500');
});

it('suma exacto una lista de valores', function () {
    expect(Decimal::sum(['0.1', '0.2', '12.345', 3]))->toBe('15.645');
});

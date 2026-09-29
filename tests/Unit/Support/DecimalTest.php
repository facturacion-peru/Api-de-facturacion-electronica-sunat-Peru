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

it('redondea mitad hacia arriba (lejos de cero) a la escala pedida', function () {
    expect(Decimal::round('2.345', 2))->toBe('2.35')
        ->and(Decimal::round('2.344', 2))->toBe('2.34')
        ->and(Decimal::round('-2.345', 2))->toBe('-2.35')
        ->and(Decimal::round('7', 2))->toBe('7.00')
        ->and(Decimal::round('0.005', 2))->toBe('0.01');
});

it('multiplica exacto y redondea el resultado', function () {
    expect(Decimal::mul('3', '4.20', 2))->toBe('12.60')
        ->and(Decimal::mul('0.375', '4.20', 2))->toBe('1.58')   // 1.575 → 1.58
        ->and(Decimal::mul('1.333', '2.99', 2))->toBe('3.99')   // 3.98567 → 3.99
        ->and(Decimal::mul('2.5', '-1.01', 2))->toBe('-2.53');  // -2.525 → -2.53
});

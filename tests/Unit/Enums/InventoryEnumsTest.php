<?php

use App\Enums\AdjustmentReason;
use App\Enums\IgvAffectation;
use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;

/*
 * T010 · Catálogos del inventario (plan 002).
 */

it('solo admiten decimales las unidades de peso, volumen y longitud', function () {
    $decimal = array_values(array_map(
        fn (UnitOfMeasure $unit) => $unit->value,
        array_filter(UnitOfMeasure::cases(), fn (UnitOfMeasure $unit) => $unit->allowsDecimals()),
    ));

    expect($decimal)->toEqualCanonicalizing(['KGM', 'GRM', 'LTR', 'MLT', 'MTR'])
        ->and(UnitOfMeasure::Unit->allowsDecimals())->toBeFalse();
});

it('usa los códigos del catálogo 03 de SUNAT', function () {
    expect(array_column(UnitOfMeasure::cases(), 'value'))
        ->toEqualCanonicalizing(['NIU', 'ZZ', 'KGM', 'GRM', 'LTR', 'MLT', 'MTR', 'BX', 'PK', 'DZN']);
});

it('usa los códigos del catálogo 07 de SUNAT para la afectación al IGV', function () {
    expect(IgvAffectation::Gravado->value)->toBe('10')
        ->and(IgvAffectation::Exonerado->value)->toBe('20')
        ->and(IgvAffectation::Inafecto->value)->toBe('30');
});

it('todas las opciones tienen etiqueta en español', function () {
    foreach ([UnitOfMeasure::class, IgvAffectation::class, ProductType::class, MovementType::class, AdjustmentReason::class] as $enum) {
        foreach ($enum::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
    }
});

it('solo los bienes controlan stock', function () {
    expect(ProductType::Good->tracksStock())->toBeTrue()
        ->and(ProductType::Service->tracksStock())->toBeFalse();
});

it('define los tipos de movimiento de RF-012', function () {
    expect(array_column(MovementType::cases(), 'value'))
        ->toEqualCanonicalizing(['entry', 'sale', 'adjustment', 'return', 'reversal']);
});

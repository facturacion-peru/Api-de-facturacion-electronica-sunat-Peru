<?php

use App\Enums\DocumentType;
use App\Enums\SunatEnvironment;
use App\Enums\SunatStatus;

/*
 * T010 · Catálogos de la configuración SUNAT (spec 004).
 */

it('define los estados de RF-010 con etiqueta', function () {
    expect(array_column(SunatStatus::cases(), 'value'))->toBe(['not_configured', 'pending', 'validated', 'error', 'inactive'])
        ->and(SunatStatus::Pending->label())->toBe('Pendiente de validación');
});

it('solo existe el ambiente beta en el MVP', function () {
    expect(SunatEnvironment::cases())->toBe([SunatEnvironment::Beta]);
});

it('valida el formato de serie por tipo de comprobante', function () {
    expect(preg_match(DocumentType::Invoice->seriesPattern(), 'F001'))->toBe(1)
        ->and(preg_match(DocumentType::Invoice->seriesPattern(), 'B001'))->toBe(0)
        ->and(preg_match(DocumentType::Receipt->seriesPattern(), 'B0A1'))->toBe(1)
        ->and(preg_match(DocumentType::Receipt->seriesPattern(), 'B01'))->toBe(0)
        ->and(preg_match(DocumentType::Receipt->seriesPattern(), 'b001'))->toBe(0)
        ->and(DocumentType::Invoice->value)->toBe('01');
});

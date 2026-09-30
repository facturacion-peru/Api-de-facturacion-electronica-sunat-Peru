<?php

use App\Enums\CustomerDocumentType;
use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionResult;

/*
 * T010 · Catálogos de la emisión de comprobantes (spec 005).
 */

it('define los estados de RF-010 y cuáles son definitivos', function () {
    expect(array_column(SalesDocumentStatus::cases(), 'value'))->toBe(['pending', 'sent', 'accepted', 'observed', 'rejected', 'discarded'])
        ->and(SalesDocumentStatus::Observed->label())->toBe('Aceptado con observaciones')
        ->and(array_values(array_filter(SalesDocumentStatus::cases(), fn ($s) => $s->isFinal())))
        ->toBe([SalesDocumentStatus::Accepted, SalesDocumentStatus::Observed, SalesDocumentStatus::Rejected, SalesDocumentStatus::Discarded]);
});

it('usa los códigos del catálogo 06 de SUNAT para el documento del cliente', function () {
    expect(CustomerDocumentType::Dni->value)->toBe('1')
        ->and(CustomerDocumentType::ForeignerCard->value)->toBe('4')
        ->and(CustomerDocumentType::Ruc->value)->toBe('6');
});

it('valida el formato de cada documento', function (CustomerDocumentType $type, string $number, bool $valid) {
    expect(preg_match($type->pattern(), $number) === 1)->toBe($valid);
})->with([
    [CustomerDocumentType::Dni, '46027897', true],
    [CustomerDocumentType::Dni, '4602789', false],
    [CustomerDocumentType::Dni, '4602789A', false],
    [CustomerDocumentType::ForeignerCard, '001234567', true],
    [CustomerDocumentType::ForeignerCard, '1234567', false],
    [CustomerDocumentType::Ruc, '20131312955', true],
    [CustomerDocumentType::Ruc, '2013131295', false],
]);

it('distingue los resultados que se reintentan', function () {
    expect(array_column(SubmissionResult::cases(), 'value'))->toBe(['accepted', 'observed', 'rejected', 'unreachable', 'error']);
});

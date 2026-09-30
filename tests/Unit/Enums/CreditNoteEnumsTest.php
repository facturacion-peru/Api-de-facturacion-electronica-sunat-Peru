<?php

use App\Enums\CorrectionStatus;
use App\Enums\CreditNoteReason;
use App\Enums\DocumentType;

/*
 * T010 · Catálogos de la spec 007 (A-39, A-40).
 */

it('la nota de crédito es el tipo 07 y su serie empieza por F o B', function () {
    expect(DocumentType::CreditNote->value)->toBe('07')
        ->and(DocumentType::CreditNote->isSale())->toBeFalse()
        ->and(DocumentType::Receipt->isSale())->toBeTrue()
        ->and(preg_match(DocumentType::CreditNote->seriesPattern(), 'FC01'))->toBe(1)
        ->and(preg_match(DocumentType::CreditNote->seriesPattern(), 'BC01'))->toBe(1)
        ->and(preg_match(DocumentType::CreditNote->seriesPattern(), 'NC01'))->toBe(0);
});

it('solo admite los motivos 01, 06 y 07 del catálogo 09', function () {
    expect(array_column(CreditNoteReason::cases(), 'value'))->toBe(['01', '06', '07'])
        ->and(CreditNoteReason::Voiding->alwaysRestocks())->toBeFalse()
        ->and(CreditNoteReason::ItemReturn->alwaysRestocks())->toBeTrue()
        ->and(CreditNoteReason::ItemReturn->coversRemainder())->toBeFalse()
        ->and(CreditNoteReason::TotalReturn->coversRemainder())->toBeTrue();
});

it('etiqueta los estados de corrección', function () {
    expect(CorrectionStatus::PartiallyReturned->label())->toBe('Devuelto parcialmente')
        ->and(array_column(CorrectionStatus::cases(), 'value'))->toBe(['none', 'partially_returned', 'fully_returned', 'voided']);
});

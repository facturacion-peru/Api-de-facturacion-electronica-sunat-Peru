<?php

use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionResult;
use App\Enums\SubmissionTrigger;
use App\Models\SalesDocument;
use App\Models\SunatSubmission;
use App\Sales\Exceptions\SalesDocumentImmutable;
use App\Tenancy\TenantContext;

/*
 * T012 · Comprobantes inmutables salvo su estado de envío (RF-006, RF-013, RF-014).
 */

beforeEach(function () {
    $this->document = SalesDocument::factory()->status(SalesDocumentStatus::Pending)->create();
    app(TenantContext::class)->set($this->document->company);
});

it('numera con la serie y 8 dígitos', function () {
    $this->document->number = 123;

    expect($this->document->display_number)->toBe($this->document->series_code.'-00000123');
});

it('solo deja cambiar los campos del envío', function () {
    $this->document->update(['status' => SalesDocumentStatus::Accepted, 'sunat_code' => '0', 'attempts' => 1]);

    expect($this->document->fresh()->status)->toBe(SalesDocumentStatus::Accepted);
});

it('no deja cambiar importes, cliente ni XML', function (array $change) {
    expect(fn () => $this->document->update($change))->toThrow(SalesDocumentImmutable::class);
})->with([
    'total' => [['total' => '99.00']],
    'cliente' => [['customer_name' => 'OTRO']],
    'xml' => [['xml' => '<otro/>']],
    'número' => [['number' => 999]],
]);

it('no se borra', function () {
    expect(fn () => $this->document->delete())->toThrow(SalesDocumentImmutable::class);
});

it('no serializa el XML ni el CDR', function () {
    expect($this->document->toArray())->not->toHaveKeys(['xml', 'cdr']);
});

it('los intentos de envío son inmutables', function () {
    $submission = SunatSubmission::create([
        'company_id' => $this->document->company_id, 'sales_document_id' => $this->document->id,
        'trigger' => SubmissionTrigger::Issue, 'started_at' => now(), 'duration_ms' => 120,
        'result' => SubmissionResult::Unreachable, 'message' => 'Tiempo agotado',
    ]);

    expect(fn () => $submission->update(['result' => SubmissionResult::Accepted]))->toThrow(SalesDocumentImmutable::class)
        ->and(fn () => $submission->delete())->toThrow(SalesDocumentImmutable::class);
});

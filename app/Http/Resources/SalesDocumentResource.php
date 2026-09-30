<?php

namespace App\Http\Resources;

use App\Enums\CorrectionStatus;
use App\Enums\SalesDocumentStatus;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SunatSubmission;
use App\Services\CreditNoteService;
use App\Sunat\ReturnedSoFar;
use Illuminate\Http\Request;

/**
 * Comprobante electrónico (spec 005) o nota de crédito (spec 007). En beta
 * viaja siempre `environment_notice` (RF-020). El XML y el CDR van por sus
 * descargas.
 *
 * @mixin SalesDocument
 */
class SalesDocumentResource extends ApiResource
{
    public const BETA_NOTICE = 'PRUEBAS — sin valor legal';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'series_code' => $this->series_code,
            'number' => $this->number,
            'display_number' => $this->display_number,
            'environment' => $this->environment->value,
            'environment_notice' => self::BETA_NOTICE,
            'issued_at' => $this->issued_at->toIso8601String(),
            'seller' => $this->seller ? ['id' => $this->seller->id, 'name' => $this->seller->name] : null,
            'payment_method' => $this->payment_method->value,
            'currency' => $this->currency,
            'customer' => [
                'id' => $this->customer_id,
                'document_type' => $this->customer_document_type,
                'document_number' => $this->customer_document_number,
                'name' => $this->customer_name,
                'address' => $this->customer_address,
            ],
            'op_gravadas' => $this->op_gravadas,
            'op_exoneradas' => $this->op_exoneradas,
            'op_inafectas' => $this->op_inafectas,
            'igv' => $this->igv,
            'discount_total' => $this->discount_total,
            'total' => $this->total,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sunat_code' => $this->sunat_code,
            'sunat_message' => $this->sunat_message,
            'sunat_notes' => $this->sunat_notes ?? [],
            'hash' => $this->hash,
            'has_cdr' => $this->cdr !== null,
            'attempts' => $this->attempts,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'can_retry' => ! $this->status->isFinal(),
            // Spec 007: corrección con notas de crédito y descarte de rechazados.
            'correction_status' => $this->correction_status->value,
            'correction_status_label' => $this->correction_status->label(),
            'can_credit' => $this->canCredit(),
            'note_reason_code' => $this->note_reason_code?->value,
            'note_reason_label' => $this->note_reason_code?->label(),
            'note_reason' => $this->note_reason,
            'restock' => $this->restock,
            'discard_reason' => $this->discard_reason,
            'reference' => $this->whenLoaded('reference', fn () => $this->reference ? [
                'id' => $this->reference->id,
                'document_type' => $this->reference->document_type->value,
                'display_number' => $this->reference->display_number,
            ] : null),
            'credit_notes' => $this->whenLoaded('creditNotes', fn () => $this->creditNotes->map(fn (SalesDocument $note) => [
                'id' => $note->id,
                'display_number' => $note->display_number,
                'note_reason_label' => $note->note_reason_code?->label(),
                'status' => $note->status->value,
                'status_label' => $note->status->label(),
                'total' => $note->total,
                'issued_at' => $note->issued_at->toIso8601String(),
            ])->all()),
            'lines' => $this->whenLoaded('lines', fn () => $this->linesWithRemaining()),
            'submissions' => $this->whenLoaded('submissions', fn () => $this->submissions->map(fn (SunatSubmission $s) => [
                'trigger' => $s->trigger->value,
                'started_at' => $s->started_at->toIso8601String(),
                'duration_ms' => $s->duration_ms,
                'result' => $s->result->value,
                'code' => $s->code,
                'message' => $s->message,
            ])->all()),
        ];
    }

    private function canCredit(): bool
    {
        return $this->document_type->isSale()
            && in_array($this->status, [SalesDocumentStatus::Accepted, SalesDocumentStatus::Observed], true)
            && ! in_array($this->correction_status, [CorrectionStatus::Voided, CorrectionStatus::FullyReturned], true);
    }

    /** Líneas con lo que queda por devolver (solo en facturas y boletas). */
    private function linesWithRemaining(): array
    {
        $returned = $this->document_type->isSale() ? CreditNoteService::returnedByLine($this->resource) : collect();

        return $this->lines->map(fn (SalesDocumentLine $line) => [
            'position' => $line->position,
            'product_code' => $line->product_code,
            'product_name' => $line->product_name,
            'unit' => $line->unit,
            'igv_affectation' => $line->igv_affectation,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'discount' => $line->discount,
            'base_amount' => $line->base_amount,
            'igv' => $line->igv,
            'amount' => $line->amount,
            'remaining' => $this->document_type->isSale()
                ? bcsub($line->quantity, ($returned[$line->id] ?? ReturnedSoFar::none())->quantity, 3)
                : null,
        ])->all();
    }
}

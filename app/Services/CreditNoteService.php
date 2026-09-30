<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CorrectionStatus;
use App\Enums\CreditNoteReason;
use App\Enums\DocumentType;
use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Enums\SunatEnvironment;
use App\Enums\SunatStatus;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\Series;
use App\Models\User;
use App\Sunat\CreditNoteCalculator;
use App\Sunat\DocumentSigner;
use App\Sunat\Exceptions\SigningFailed;
use App\Sunat\ReturnedSoFar;
use App\Sunat\SunatDispatcher;
use App\Sunat\TaxCalculator;
use App\Sunat\UblBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Notas de crédito sobre facturas y boletas aceptadas (spec 007).
 *
 * Como la emisión de la 005: una transacción con idempotencia, correlativo
 * bloqueado, cálculo, XML firmado y reposición de stock; el envío va tras el
 * commit. La fila del comprobante original se bloquea mientras tanto, para
 * que dos notas simultáneas nunca devuelvan más de lo emitido (RF-005).
 */
class CreditNoteService
{
    public function __construct(
        private AuditLogger $audit,
        private InventoryService $inventory,
        private SeriesService $series,
        private SunatConfigService $sunat,
        private TaxCalculator $calculator,
        private UblBuilder $builder,
        private DocumentSigner $signer,
        private SunatDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{idempotency_key: string, reason_code: string, reason: string, restock?: bool, series_id?: ?int, lines?: list<array{line_position: int, quantity: string}>}  $data
     * @return array{SalesDocument, bool} la nota y si se creó ahora
     */
    public function issue(SalesDocument $original, array $data, User $actor): array
    {
        if ($existing = SalesDocument::where('idempotency_key', $data['idempotency_key'])->first()) {
            return [$existing, false];
        }

        try {
            $note = DB::transaction(fn () => $this->create($original, $data, $actor));
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = SalesDocument::where('idempotency_key', $data['idempotency_key'])->first()) {
                return [$existing, false];
            }

            throw $e;
        }

        $this->dispatcher->send($note, SubmissionTrigger::Issue, $actor);

        return [$note->refresh(), true];
    }

    /**
     * Lo devuelto por línea del comprobante con notas que no fueron
     * rechazadas (las pendientes cuentan: su stock ya se repuso).
     *
     * @return Collection<int, ReturnedSoFar> por id de línea original
     */
    public static function returnedByLine(SalesDocument $original): Collection
    {
        $noteIds = SalesDocument::where('reference_document_id', $original->id)
            ->where('status', '!=', SalesDocumentStatus::Rejected)->pluck('id');

        return SalesDocumentLine::whereIn('sales_document_id', $noteIds)->get()->groupBy('reference_line_id')
            ->map(fn ($lines) => $lines->reduce(fn (ReturnedSoFar $sofar, $l) => $sofar->plus($l->quantity, $l->gross_amount, $l->discount), ReturnedSoFar::none()));
    }

    /** Estado de corrección según las notas aceptadas u observadas (se llama al aceptarse una). */
    public static function correctionStatusOf(SalesDocument $original): CorrectionStatus
    {
        $accepted = SalesDocument::where('reference_document_id', $original->id)
            ->whereIn('status', [SalesDocumentStatus::Accepted, SalesDocumentStatus::Observed])->with('lines')->get();

        if ($accepted->isEmpty()) {
            return CorrectionStatus::None;
        }
        if ($accepted->contains(fn ($n) => $n->note_reason_code === CreditNoteReason::Voiding)) {
            return CorrectionStatus::Voided;
        }

        $returned = $accepted->flatMap->lines->groupBy('reference_line_id')->map(fn ($l) => $l->reduce(fn ($c, $x) => bcadd($c, $x->quantity, 3), '0'));
        $complete = $original->lines()->get()->every(fn ($line) => bccomp($returned[$line->id] ?? '0', $line->quantity, 3) >= 0);

        return $complete ? CorrectionStatus::FullyReturned : CorrectionStatus::PartiallyReturned;
    }

    private function create(SalesDocument $original, array $data, User $actor): SalesDocument
    {
        $original = SalesDocument::whereKey($original->id)->lockForUpdate()->firstOrFail();
        $original->load(['lines.product', 'series.establishment', 'company.mainEstablishment']);
        $reason = CreditNoteReason::from($data['reason_code']);

        $this->ensureCorrectable($original);
        $selection = $this->selection($original, $reason, $data['lines'] ?? []);
        $restock = $reason->alwaysRestocks() || ($data['restock'] ?? true);
        $series = $this->noteSeries($original, $data['series_id'] ?? null);

        $calc = new CreditNoteCalculator($this->calculator);
        $calculated = [];
        foreach ($selection as [$line, $quantity, $sofar]) {
            $calculated[] = $calc->line($line->quantity, $line->unit_price, $line->discount, \App\Enums\IgvAffectation::from($line->igv_affectation), $quantity, $sofar);
        }
        $totals = $this->calculator->totals($calculated);

        $note = new SalesDocument([
            ...collect($original->getAttributes())->only([
                'company_id', 'payment_method', 'currency', 'issuer_ruc', 'issuer_name', 'issuer_trade_name', 'issuer_address', 'issuer_ubigeo',
                'issuer_department', 'issuer_province', 'issuer_district', 'customer_id', 'customer_document_type', 'customer_document_number',
                'customer_name', 'customer_address',
            ])->all(),
            'series_id' => $series->id,
            'reference_document_id' => $original->id,
            'note_reason_code' => $reason,
            'note_reason' => $data['reason'],
            'restock' => $restock,
            'document_type' => DocumentType::CreditNote,
            'series_code' => $series->code,
            'number' => $this->series->nextNumber($series),
            'environment' => SunatEnvironment::Beta,
            'issued_at' => now(),
            'seller_id' => $actor->id,
            'op_gravadas' => $totals->opGravadas,
            'op_exoneradas' => $totals->opExoneradas,
            'op_inafectas' => $totals->opInafectas,
            'igv' => $totals->igv,
            'discount_total' => $totals->discountTotal,
            'total' => $totals->total,
            'status' => SalesDocumentStatus::Pending,
            'next_attempt_at' => now(),
            'idempotency_key' => $data['idempotency_key'],
        ]);

        $lines = [];
        foreach ($selection as $i => [$line, $quantity]) {
            $c = $calculated[$i];
            $lines[] = new SalesDocumentLine([
                'company_id' => $original->company_id,
                'product_id' => $line->product_id,
                'reference_line_id' => $line->id,
                'position' => $i + 1,
                'product_code' => $line->product_code,
                'product_name' => $line->product_name,
                'unit' => $line->unit,
                'igv_affectation' => $line->igv_affectation,
                'quantity' => $quantity,
                'unit_price' => $line->unit_price,
                'unit_value' => $c->unitValue,
                'gross_amount' => $c->grossAmount,
                'discount' => $c->discount,
                'base_amount' => $c->baseAmount,
                'igv' => $c->igv,
                'amount' => $c->amount,
            ]);
        }
        $note->setRelation('lines', collect($lines));
        $note->setRelation('reference', $original);

        $establishment = $series->establishment ?? $original->company->mainEstablishment;
        try {
            $signed = $this->signer->sign($this->builder->build($note, $establishment->code), (string) $this->sunat->currentCertificate($original->company)?->pem);
        } catch (SigningFailed $e) {
            throw ValidationException::withMessages(['sunat' => $e->getMessage()]);
        }
        $note->fill(['xml' => $signed->xml, 'hash' => $signed->hash]);
        $note->save();

        foreach ($lines as $i => $lineModel) {
            $note->lines()->save($lineModel);
            $product = $selection[$i][0]->product;
            if ($restock && $product !== null) {
                $this->inventory->restock($product, $lineModel->quantity, $original, $note, $actor);
            }
        }

        $this->audit->record('credit_note.issued', $note, [
            'number' => $note->display_number,
            'reference' => $original->display_number,
            'reason_code' => $reason->value,
            'total' => $note->total,
            'restock' => $restock,
        ], actor: $actor);

        return $note;
    }

    private function ensureCorrectable(SalesDocument $original): void
    {
        if (! $original->document_type->isSale()
            || ! in_array($original->status, [SalesDocumentStatus::Accepted, SalesDocumentStatus::Observed], true)) {
            throw ValidationException::withMessages(['sales_document' => 'Solo se emiten notas de crédito sobre comprobantes aceptados por SUNAT.']);
        }

        [$status] = $this->sunat->effectiveStatus($original->company);
        if ($status !== SunatStatus::Validated) {
            throw ValidationException::withMessages(['sunat' => "La emisión SUNAT no está disponible: {$status->label()}."]);
        }
    }

    /**
     * Líneas y cantidades de la nota: todo lo que queda (01, 06) o lo elegido (07).
     *
     * @return list<array{SalesDocumentLine, string, ReturnedSoFar}>
     */
    private function selection(SalesDocument $original, CreditNoteReason $reason, array $requested): array
    {
        $returned = self::returnedByLine($original);
        $remaining = fn (SalesDocumentLine $line) => bcsub($line->quantity, ($returned[$line->id] ?? ReturnedSoFar::none())->quantity, 3);

        if ($original->lines->every(fn ($line) => bccomp($remaining($line), '0', 3) <= 0)) {
            throw ValidationException::withMessages(['sales_document' => 'Este comprobante ya fue anulado o devuelto totalmente.']);
        }

        if ($reason->coversRemainder()) {
            return $original->lines->filter(fn ($line) => bccomp($remaining($line), '0', 3) > 0)
                ->map(fn ($line) => [$line, $remaining($line), $returned[$line->id] ?? ReturnedSoFar::none()])->values()->all();
        }

        $byPosition = $original->lines->keyBy('position');
        $selection = [];
        foreach ($requested as $i => $item) {
            $line = $byPosition[$item['line_position']] ?? null;
            if ($line === null) {
                throw ValidationException::withMessages(["lines.{$i}.line_position" => "El comprobante no tiene la línea {$item['line_position']}."]);
            }

            $quantity = bcadd((string) $item['quantity'], '0', 3);
            if (bccomp($quantity, $remaining($line), 3) > 0) {
                $left = rtrim(rtrim($remaining($line), '0'), '.');
                throw ValidationException::withMessages(["lines.{$i}.quantity" => "Solo quedan {$left} por devolver de esta línea."]);
            }

            $selection[] = [$line, $quantity, $returned[$line->id] ?? ReturnedSoFar::none()];
        }

        return $selection;
    }

    /** Serie de nota con la letra del comprobante: F… para facturas, B… para boletas (RF-003). */
    private function noteSeries(SalesDocument $original, ?int $seriesId): Series
    {
        $prefix = $original->document_type->seriesPrefix();
        $of = $original->document_type === DocumentType::Invoice ? 'facturas' : 'boletas';

        if ($seriesId !== null) {
            $series = Series::with('establishment')->findOrFail($seriesId);
            if ($series->document_type !== DocumentType::CreditNote || ! str_starts_with($series->code, $prefix) || ! $series->active) {
                throw ValidationException::withMessages(['series_id' => "La serie {$series->code} no corresponde a notas de {$of}."]);
            }

            return $series;
        }

        return Series::with('establishment')->where('document_type', DocumentType::CreditNote)->where('active', true)
            ->where('code', 'like', "{$prefix}%")->orderBy('code')->first()
            ?? throw ValidationException::withMessages(['series_id' => "No hay una serie de notas de crédito de {$of} activa ({$prefix}…). Pide al administrador que cree una, p. ej. {$prefix}C01."]);
    }
}

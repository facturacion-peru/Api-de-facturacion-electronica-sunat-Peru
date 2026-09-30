<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\AdjustmentReason;
use App\Enums\CustomerDocumentType;
use App\Enums\DocumentType;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Enums\SunatEnvironment;
use App\Enums\SunatStatus;
use App\Enums\TaxRegime;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\Series;
use App\Models\User;
use App\Sunat\DocumentSigner;
use App\Sunat\Exceptions\SigningFailed;
use App\Sunat\SunatDispatcher;
use App\Sunat\TaxCalculator;
use App\Sunat\UblBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emisión de boletas y facturas en beta (spec 005).
 *
 * Todo en una transacción (RF-004): comprobaciones, correlativo bloqueado,
 * copia de datos, cálculo, XML firmado y descuento de stock. Si algo falla
 * (sin stock, firma) no queda nada, ni el número (RF-005). El envío a SUNAT
 * va después del commit y nunca deshace la venta.
 */
class SalesDocumentService
{
    /** Tope para emitir una boleta sin identificar al comprador (A-22 ⚖️). */
    public const ANONYMOUS_RECEIPT_LIMIT = '700.00';

    public const ANONYMOUS_CUSTOMER = ['0', '-', 'CLIENTES VARIOS'];

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
     * @param  array{idempotency_key: string, document_type: string, series_id?: ?int, customer_id?: ?int, payment_method: string, lines: list<array{product_id: int, quantity: string, discount?: ?string}>}  $data
     * @return array{SalesDocument, bool} el comprobante y si se creó ahora
     */
    public function issue(array $data, User $actor): array
    {
        if ($existing = $this->findByKey($data['idempotency_key'])) {
            return [$existing, false];
        }

        try {
            $document = DB::transaction(fn () => $this->create($data, $actor));
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones simultáneas con la misma clave: gana la primera.
            if ($existing = $this->findByKey($data['idempotency_key'])) {
                return [$existing, false];
            }

            throw $e;
        }

        $this->dispatcher->send($document, SubmissionTrigger::Issue, $actor);

        return [$document->refresh(), true];
    }

    private function create(array $data, User $actor): SalesDocument
    {
        $company = $actor->membership->company()->with('mainEstablishment')->firstOrFail();
        $type = DocumentType::from($data['document_type']);

        $this->ensureCanIssue($company, $type);
        $series = $this->resolveSeries($type, $data['series_id'] ?? null);
        $customer = isset($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;

        $products = Product::whereIn('id', collect($data['lines'])->pluck('product_id'))->get()->keyBy('id');
        $calculated = [];
        foreach ($data['lines'] as $line) {
            $product = $products[$line['product_id']];
            $calculated[] = $this->calculator->line(
                (string) $line['quantity'], $product->sale_price, (string) ($line['discount'] ?? '0'), $product->igv_affectation,
            );
        }
        $totals = $this->calculator->totals($calculated);

        $this->ensureCustomer($type, $customer, $totals->total);
        [$customerType, $customerNumber, $customerName] = $customer
            ? [$customer->document_type->value, $customer->document_number, $customer->name]
            : self::ANONYMOUS_CUSTOMER;

        $establishment = $series->establishment ?? $company->mainEstablishment;
        $district = $establishment->district()->with(['provincia', 'region'])->first();

        $document = new SalesDocument([
            'company_id' => $company->id,
            'series_id' => $series->id,
            'document_type' => $type,
            'series_code' => $series->code,
            'number' => $this->series->nextNumber($series),
            'environment' => SunatEnvironment::Beta,
            'issued_at' => now(),
            'seller_id' => $actor->id,
            'payment_method' => PaymentMethod::from($data['payment_method']),
            'currency' => 'PEN',
            'issuer_ruc' => $company->ruc,
            'issuer_name' => $company->razon_social,
            'issuer_trade_name' => $company->nombre_comercial,
            'issuer_address' => $establishment->address,
            'issuer_ubigeo' => $establishment->ubigeo,
            'issuer_department' => $district?->region?->nombre,
            'issuer_province' => $district?->provincia?->nombre,
            'issuer_district' => $district?->nombre,
            'customer_id' => $customer?->id,
            'customer_document_type' => $customerType,
            'customer_document_number' => $customerNumber,
            'customer_name' => $customerName,
            'customer_address' => $customer?->address,
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
        foreach ($data['lines'] as $i => $line) {
            $product = $products[$line['product_id']];
            $c = $calculated[$i];
            $lines[] = new SalesDocumentLine([
                'company_id' => $company->id,
                'product_id' => $product->id,
                'position' => $i + 1,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit' => $product->unit->value,
                'igv_affectation' => $product->igv_affectation->value,
                'quantity' => (string) $line['quantity'],
                'unit_price' => $product->sale_price,
                'unit_value' => $c->unitValue,
                'gross_amount' => $c->grossAmount,
                'discount' => $c->discount,
                'base_amount' => $c->baseAmount,
                'igv' => $c->igv,
                'amount' => $c->amount,
            ]);
        }
        $document->setRelation('lines', collect($lines));

        // Se firma antes de guardar: si falla, la transacción no deja nada (RF-005).
        $signed = $this->sign($document, $company, $establishment->code);
        $document->fill(['xml' => $signed->xml, 'hash' => $signed->hash]);
        $document->save();

        foreach ($lines as $i => $lineModel) {
            $document->lines()->save($lineModel);
            $this->inventory->consume($products[$data['lines'][$i]['product_id']], $lineModel->quantity, $document, $actor);
        }

        $this->audit->record('sales_document.issued', $document, [
            'number' => $document->display_number,
            'document_type' => $type->value,
            'total' => $document->total,
            'customer' => $customerNumber,
        ], actor: $actor);

        return $document;
    }

    /** RF-002 y A-11: configuración validada; el Nuevo RUS no emite facturas. */
    private function ensureCanIssue(Company $company, DocumentType $type): void
    {
        [$status] = $this->sunat->effectiveStatus($company);

        if ($status !== SunatStatus::Validated) {
            throw ValidationException::withMessages(['sunat' => "La emisión SUNAT no está disponible: {$status->label()}."]);
        }

        if ($type === DocumentType::Invoice && $company->tax_regime === TaxRegime::Nrus) {
            throw ValidationException::withMessages(['document_type' => 'Las empresas del Nuevo RUS no emiten facturas.']);
        }
    }

    private function resolveSeries(DocumentType $type, ?int $seriesId): Series
    {
        if ($seriesId !== null) {
            $series = Series::with('establishment')->findOrFail($seriesId);

            return match (true) {
                $series->document_type !== $type => throw ValidationException::withMessages(['series_id' => "La serie {$series->code} no es de {$type->label()}."]),
                ! $series->active => throw ValidationException::withMessages(['series_id' => "La serie {$series->code} está desactivada."]),
                default => $series,
            };
        }

        $active = Series::with('establishment')->where('document_type', $type)->where('active', true)->orderBy('code')->get();

        return match ($active->count()) {
            0 => throw ValidationException::withMessages(['series_id' => "No hay una serie de {$type->label()} activa. Pide al administrador que cree o active una."]),
            1 => $active->first(),
            default => throw ValidationException::withMessages(['series_id' => 'Elige la serie: '.$active->pluck('code')->implode(', ').'.']),
        };
    }

    /** HU-1.2 y HU-2.2: la factura exige RUC; la boleta de más de S/ 700, documento ⚖️. */
    private function ensureCustomer(DocumentType $type, ?Customer $customer, string $total): void
    {
        if ($type === DocumentType::Invoice && $customer?->document_type !== CustomerDocumentType::Ruc) {
            throw ValidationException::withMessages(['customer_id' => 'La factura requiere un cliente con RUC. Para este cliente, emite una boleta.']);
        }

        if ($type === DocumentType::Receipt && $customer === null && bccomp($total, self::ANONYMOUS_RECEIPT_LIMIT, 2) > 0) {
            throw ValidationException::withMessages(['customer_id' => 'Las boletas de más de S/ 700 requieren el documento del comprador.']);
        }
    }

    private function sign(SalesDocument $document, Company $company, string $establishmentCode)
    {
        $certificate = $this->sunat->currentCertificate($company);

        try {
            return $this->signer->sign($this->builder->build($document, $establishmentCode), (string) $certificate?->pem);
        } catch (SigningFailed $e) {
            throw ValidationException::withMessages(['sunat' => $e->getMessage()]);
        }
    }

    private function findByKey(string $key): ?SalesDocument
    {
        return SalesDocument::where('idempotency_key', $key)->first();
    }

    /**
     * Spec 007, HU-3 (A-42): un comprobante rechazado no existe para SUNAT,
     * pero su venta descontó stock. Se descarta: se revierten sus ventas y
     * su número queda usado (no se reutiliza).
     */
    public function discard(SalesDocument $document, string $reason, User $actor): SalesDocument
    {
        return DB::transaction(function () use ($document, $reason, $actor) {
            $document = SalesDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->status !== SalesDocumentStatus::Rejected) {
                throw ValidationException::withMessages(['sales_document' => 'Solo se descartan comprobantes rechazados por SUNAT.']);
            }

            InventoryMovement::where('type', MovementType::Sale)
                ->where('source_type', $document->getMorphClass())->where('source_id', $document->id)
                ->whereDoesntHave('reversal')->orderBy('id')->get()
                ->each(fn (InventoryMovement $sale) => $this->inventory->reverse(
                    $sale, AdjustmentReason::Error, "Comprobante {$document->display_number} descartado", $actor, fromSource: true,
                ));

            $document->update([
                'status' => SalesDocumentStatus::Discarded,
                'discarded_at' => now(),
                'discarded_by' => $actor->id,
                'discard_reason' => $reason,
                'next_attempt_at' => null,
            ]);

            $this->audit->record('sales_document.discarded', $document, [
                'number' => $document->display_number,
                'reason' => $reason,
            ], actor: $actor);

            return $document;
        });
    }
}

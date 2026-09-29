<?php

namespace App\Http\Controllers\Api\Sales;

use App\Audit\AuditLogger;
use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\IndexSalesDocumentRequest;
use App\Http\Requests\Sales\SalesDocumentPdfRequest;
use App\Http\Requests\Sales\StoreSalesDocumentRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SalesDocumentResource;
use App\Models\SalesDocument;
use App\Sales\DocumentPdf;
use App\Services\SalesDocumentService;
use App\Sunat\SunatDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Boletas y facturas electrónicas (spec 005). */
class SalesDocumentController extends Controller
{
    public function __construct(private SalesDocumentService $documents) {}

    /**
     * Todos los comprobantes de la empresa (A-32), del más reciente al más
     * antiguo, con el número de pendientes y rechazados para avisar (HU-6.1).
     */
    public function index(IndexSalesDocumentRequest $request): ApiCollection
    {
        $filters = $request->validated();
        $customer = mb_strtolower(trim((string) ($filters['customer'] ?? '')));

        $page = SalesDocument::query()
            ->with('seller')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('issued_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('issued_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($filters['document_type'] ?? null, fn ($q, $type) => $q->where('document_type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($customer !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('customer_document_number', 'like', "{$customer}%")
                ->orWhereRaw('LOWER(customer_name) LIKE ?', ["%{$customer}%"])))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $counts = SalesDocument::query()
            ->whereIn('status', [SalesDocumentStatus::Pending, SalesDocumentStatus::Sent, SalesDocumentStatus::Rejected])
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return SalesDocumentResource::collection($page)->additional(['counts' => [
            'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['sent'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
        ]]);
    }

    public function show(SalesDocument $salesDocument): SalesDocumentResource
    {
        return SalesDocumentResource::make($salesDocument->load(['lines', 'seller', 'submissions']));
    }

    /** 201 si se creó; 200 si la clave de idempotencia ya existía. */
    public function store(StoreSalesDocumentRequest $request): JsonResponse
    {
        [$document, $created] = $this->documents->issue($request->validated(), $request->user());

        return SalesDocumentResource::make($document->load(['lines', 'seller', 'submissions']))
            ->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * HU-4.3: reenvío inmediato. Cualquier usuario de la empresa (A-32).
     * 409 si otro proceso ya lo está enviando (RF-012).
     */
    public function retry(Request $request, SalesDocument $salesDocument, SunatDispatcher $dispatcher, AuditLogger $audit): SalesDocumentResource
    {
        if ($salesDocument->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => "El comprobante ya tiene una respuesta definitiva de SUNAT ({$salesDocument->status->label()}).",
            ]);
        }

        $audit->record('sales_document.retry_requested', $salesDocument, ['number' => $salesDocument->display_number], actor: $request->user());

        abort_unless($dispatcher->send($salesDocument, SubmissionTrigger::Manual, $request->user()), 409, 'Este comprobante ya se está enviando a SUNAT.');

        return SalesDocumentResource::make($salesDocument->refresh()->load(['lines', 'seller', 'submissions']));
    }

    public function pdf(SalesDocumentPdfRequest $request, SalesDocument $salesDocument, DocumentPdf $pdf): Response
    {
        $format = $request->validated('format', 'a4');

        return response($pdf->render($salesDocument->load(['lines', 'company']), $format), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$salesDocument->display_number}-{$format}.pdf\"",
        ]);
    }

    /** XML firmado, con el nombre que usa SUNAT: RUC-tipo-serie-número. */
    public function xml(SalesDocument $salesDocument): Response
    {
        return response($salesDocument->xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$this->sunatName($salesDocument)}.xml\"",
        ]);
    }

    public function cdr(SalesDocument $salesDocument): Response|JsonResponse
    {
        // Respuesta directa: el manejador global unifica los 404 para no revelar
        // recursos de otras empresas, pero este comprobante sí es de la empresa.
        if ($salesDocument->cdr === null) {
            return response()->json(['success' => false, 'message' => 'SUNAT aún no devolvió el CDR de este comprobante.'], 404);
        }

        return response(base64_decode($salesDocument->cdr), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"R-{$this->sunatName($salesDocument)}.zip\"",
        ]);
    }

    private function sunatName(SalesDocument $document): string
    {
        return "{$document->issuer_ruc}-{$document->document_type->value}-{$document->series_code}-{$document->number}";
    }
}

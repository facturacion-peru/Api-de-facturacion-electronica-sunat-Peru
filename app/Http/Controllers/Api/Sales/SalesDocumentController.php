<?php

namespace App\Http\Controllers\Api\Sales;

use App\Audit\AuditLogger;
use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\IndexSalesDocumentRequest;
use App\Http\Requests\Sales\StoreSalesDocumentRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SalesDocumentResource;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use App\Sunat\SunatDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}

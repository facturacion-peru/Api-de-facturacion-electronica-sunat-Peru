<?php

namespace App\Http\Controllers\Api\Sales;

use App\Audit\AuditLogger;
use App\Enums\SubmissionTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesDocumentRequest;
use App\Http\Resources\SalesDocumentResource;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use App\Sunat\SunatDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Boletas y facturas electrónicas (spec 005). */
class SalesDocumentController extends Controller
{
    public function __construct(private SalesDocumentService $documents) {}

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

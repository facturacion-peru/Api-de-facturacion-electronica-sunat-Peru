<?php

namespace App\Http\Controllers\Api\Platform;

use App\Audit\AuditLogger;
use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexSupportDocumentRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\PlatformSalesDocumentResource;
use App\Models\SalesDocument;
use App\Sunat\SunatDispatcher;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Soporte de la emisión para la plataforma (spec 006, HU-5): lee entre
 * empresas sin contexto (withoutTenancy) y reintenta dentro del contexto de
 * la empresa del comprobante.
 */
class EmissionSupportController extends Controller
{
    public function index(IndexSupportDocumentRequest $request): ApiCollection
    {
        $statuses = match ($request->validated('status')) {
            'pending' => [SalesDocumentStatus::Pending, SalesDocumentStatus::Sent],
            'rejected' => [SalesDocumentStatus::Rejected],
            default => [SalesDocumentStatus::Pending, SalesDocumentStatus::Sent, SalesDocumentStatus::Rejected],
        };

        $documents = SalesDocument::withoutTenancy()->with('company')
            ->whereIn('status', $statuses)
            ->when($request->validated('company_id'), fn ($q, $company) => $q->where('company_id', $company))
            ->orderBy('issued_at')->orderBy('id')
            ->paginate(25)->withQueryString();

        return PlatformSalesDocumentResource::collection($documents);
    }

    public function retry(Request $request, SalesDocument $platformDocument, SunatDispatcher $dispatcher, TenantContext $tenant, AuditLogger $audit): PlatformSalesDocumentResource
    {
        $document = $platformDocument;

        if ($document->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => "El comprobante ya tiene una respuesta definitiva de SUNAT ({$document->status->label()}).",
            ]);
        }

        $sent = $tenant->run($document->company, function () use ($document, $dispatcher, $audit, $request) {
            $audit->record('sales_document.retry_requested', $document, ['number' => $document->display_number, 'by' => 'platform'], actor: $request->user());

            return $dispatcher->send($document, SubmissionTrigger::Manual, $request->user());
        });

        abort_unless($sent, 409, 'Este comprobante ya se está enviando a SUNAT.');

        return PlatformSalesDocumentResource::make(SalesDocument::withoutTenancy()->with('company')->findOrFail($document->id));
    }
}

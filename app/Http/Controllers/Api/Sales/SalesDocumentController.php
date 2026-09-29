<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesDocumentRequest;
use App\Http\Resources\SalesDocumentResource;
use App\Services\SalesDocumentService;
use Illuminate\Http\JsonResponse;

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
}

<?php

namespace App\Http\Controllers\Api\Sunat;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sunat\StoreSeriesRequest;
use App\Http\Requests\Sunat\UpdateSeriesRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SeriesResource;
use App\Models\Series;
use App\Services\SeriesService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Series de la propia empresa (HU-3). Listar: todos; crear y desactivar: administrador. */
class SeriesController extends Controller
{
    public function __construct(private SeriesService $series) {}

    public function index(): ApiCollection
    {
        return SeriesResource::collection(Series::with('establishment')->orderBy('document_type')->orderBy('code')->get());
    }

    public function store(StoreSeriesRequest $request, TenantContext $tenant): JsonResponse
    {
        $series = $this->series->create(
            $tenant->company(),
            DocumentType::from($request->validated('document_type')),
            $request->validated('code'),
            (int) $request->validated('last_number', 0),
            $request->user(),
        );

        return SeriesResource::make($series->load('establishment'))->response()->setStatusCode(201);
    }

    public function update(UpdateSeriesRequest $request, Series $series): SeriesResource
    {
        return SeriesResource::make($this->series->setActive($series, $request->boolean('active'), $request->user())->load('establishment'));
    }
}

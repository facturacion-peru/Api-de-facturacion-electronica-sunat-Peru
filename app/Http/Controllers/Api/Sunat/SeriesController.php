<?php

namespace App\Http\Controllers\Api\Sunat;

use App\Enums\DocumentType;
use App\Establishments\EstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sunat\IndexSeriesRequest;
use App\Http\Requests\Sunat\StoreSeriesRequest;
use App\Http\Requests\Sunat\UpdateSeriesRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SeriesResource;
use App\Models\Establishment;
use App\Models\Series;
use App\Services\SeriesService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Series de la propia empresa (HU-3). Listar: todos; crear y desactivar: administrador. */
class SeriesController extends Controller
{
    public function __construct(private SeriesService $series) {}

    /** Series de los locales que el usuario puede leer, o de uno (spec 017). */
    public function index(IndexSeriesRequest $request, EstablishmentAccess $access): ApiCollection
    {
        $ids = $access->filter($request->user(), $request->integer('establishment_id') ?: null);

        return SeriesResource::collection(Series::with('establishment')->whereIn('establishment_id', $ids)->orderBy('document_type')->orderBy('code')->get());
    }

    public function store(StoreSeriesRequest $request, TenantContext $tenant): JsonResponse
    {
        $series = $this->series->create(
            $tenant->company(),
            DocumentType::from($request->validated('document_type')),
            $request->validated('code'),
            (int) $request->validated('last_number', 0),
            $request->user(),
            $request->validated('establishment_id') ? Establishment::findOrFail($request->validated('establishment_id')) : null,
        );

        return SeriesResource::make($series->load('establishment'))->response()->setStatusCode(201);
    }

    public function update(UpdateSeriesRequest $request, Series $series): SeriesResource
    {
        return SeriesResource::make($this->series->setActive($series, $request->boolean('active'), $request->user())->load('establishment'));
    }
}

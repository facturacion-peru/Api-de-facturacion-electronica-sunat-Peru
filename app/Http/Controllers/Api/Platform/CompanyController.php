<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexCompanyRequest;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\PlatformCompanyDetailResource;
use App\Http\Resources\PlatformCompanyResource;
use App\Models\Company;
use App\Platform\CompanySummaries;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestión de empresas por el administrador de la plataforma (specs 001 y 006). */
class CompanyController extends Controller
{
    public function __construct(
        private CompanyService $companies,
        private CompanySummaries $summaries,
    ) {}

    /** HU-1.1/1.2: metadatos y contadores, con búsqueda y filtros. */
    public function index(IndexCompanyRequest $request): ApiCollection
    {
        $search = mb_strtolower(trim((string) $request->validated('search')));

        $query = Company::query()->select('companies.*')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('ruc', 'like', "{$search}%")
                ->orWhereRaw('LOWER(razon_social) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(nombre_comercial) LIKE ?', ["%{$search}%"])))
            ->when($request->validated('status'), fn ($q, $status) => $q->where('active', $status === 'active'))
            ->when($request->boolean('issues'), fn ($q) => $this->summaries->withIssues($q));

        return PlatformCompanyResource::collection(
            $this->summaries->withCounts($query)->orderBy('razon_social')->paginate(20)->withQueryString()
        );
    }

    public function show(Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($company);
    }

    public function update(UpdateCompanyRequest $request, Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($this->companies->update($company, $request->validated(), $request->user()));
    }

    public function activate(Request $request, Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($this->companies->setActive($company, true, $request->user()));
    }

    public function deactivate(Request $request, Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($this->companies->setActive($company, false, $request->user()));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $created = $this->companies->create($request->validated(), $request->user());

        return $this->detail($created->company)->response()->setStatusCode(201);
    }

    private function detail(Company $company): PlatformCompanyDetailResource
    {
        $fresh = $this->summaries->withCounts(Company::query()->select('companies.*'))
            ->with('mainEstablishment.district')->findOrFail($company->id);

        return PlatformCompanyDetailResource::make($fresh);
    }
}

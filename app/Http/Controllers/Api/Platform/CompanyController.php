<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestión de empresas por el administrador de la plataforma. */
class CompanyController extends Controller
{
    public function __construct(private CompanyService $companies) {}

    public function index(): ApiCollection
    {
        return CompanyResource::collection(
            Company::with('mainEstablishment.district')->orderBy('razon_social')->paginate(20)
        );
    }

    public function show(Company $company): CompanyResource
    {
        return CompanyResource::make($company->load('mainEstablishment.district'));
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $company = $this->companies->update($company, $request->validated(), $request->user());

        return CompanyResource::make($company->load('mainEstablishment.district'));
    }

    public function activate(Request $request, Company $company): CompanyResource
    {
        return CompanyResource::make($this->companies->setActive($company, true, $request->user())->load('mainEstablishment.district'));
    }

    public function deactivate(Request $request, Company $company): CompanyResource
    {
        return CompanyResource::make($this->companies->setActive($company, false, $request->user())->load('mainEstablishment.district'));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $created = $this->companies->create($request->validated(), $request->user());

        return CompanyResource::make($created->company)->response()->setStatusCode(201);
    }
}

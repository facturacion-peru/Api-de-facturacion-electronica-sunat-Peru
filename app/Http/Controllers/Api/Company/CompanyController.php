<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyLogoRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyService;
use App\Tenancy\TenantContext;

/** La empresa del usuario autenticado, sin identificador en la URL (HU-5). */
class CompanyController extends Controller
{
    public function __construct(
        private CompanyService $companies,
        private TenantContext $tenant,
    ) {}

    public function show(): CompanyResource
    {
        return CompanyResource::make($this->current());
    }

    public function update(UpdateCompanyRequest $request): CompanyResource
    {
        $company = $this->companies->update($this->current(), $request->validated(), $request->user());

        return CompanyResource::make($company);
    }

    public function updateLogo(UpdateCompanyLogoRequest $request): CompanyResource
    {
        $company = $this->companies->updateLogo($this->current(), $request->file('logo'), $request->user());

        return CompanyResource::make($company);
    }

    private function current(): Company
    {
        return $this->tenant->company()->load('mainEstablishment.district');
    }
}

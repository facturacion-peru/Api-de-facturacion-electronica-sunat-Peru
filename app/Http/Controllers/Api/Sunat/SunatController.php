<?php

namespace App\Http\Controllers\Api\Sunat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sunat\UpdateSunatCredentialsRequest;
use App\Http\Requests\Sunat\UploadCertificateRequest;
use App\Http\Resources\SunatSettingsResource;
use App\Models\Certificate;
use App\Models\Company;
use App\Services\SunatConfigService;
use App\Tenancy\TenantContext;

/** Configuración SUNAT de la propia empresa (spec 004). Solo el administrador. */
class SunatController extends Controller
{
    public function __construct(
        private SunatConfigService $config,
        private TenantContext $tenant,
    ) {}

    public function settings(): SunatSettingsResource
    {
        return $this->resource($this->tenant->company());
    }

    public function updateCredentials(UpdateSunatCredentialsRequest $request): SunatSettingsResource
    {
        $company = $this->tenant->company();
        $this->config->updateCredentials($company, $request->validated('sol_user'), $request->validated('sol_password'), $request->user());

        return $this->resource($company);
    }

    public function uploadCertificate(UploadCertificateRequest $request): SunatSettingsResource
    {
        $company = $this->tenant->company();
        $this->config->uploadCertificate($company, $request->file('certificate')->get(), $request->validated('password'), $request->user());

        return $this->resource($company);
    }

    private function resource(Company $company): SunatSettingsResource
    {
        return new SunatSettingsResource(
            $this->config->settingFor($company),
            Certificate::with('uploader')->where('company_id', $company->id)->latest('id')->get(),
        );
    }
}

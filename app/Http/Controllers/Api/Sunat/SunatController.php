<?php

namespace App\Http\Controllers\Api\Sunat;

use App\Enums\CompanyRole;
use App\Enums\SunatStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sunat\UpdateSunatCredentialsRequest;
use App\Http\Requests\Sunat\UploadCertificateRequest;
use App\Http\Resources\SunatSettingsResource;
use App\Http\Resources\SunatStatusResource;
use App\Models\Certificate;
use App\Models\Company;
use App\Services\SunatConfigService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Configuración SUNAT de la propia empresa (spec 004). Solo el administrador. */
class SunatController extends Controller
{
    public function __construct(
        private SunatConfigService $config,
        private TenantContext $tenant,
    ) {}

    /** Para cualquier rol: ¿se puede emitir?, ¿qué falta?, ¿vence el certificado? */
    public function status(): SunatStatusResource
    {
        $company = $this->tenant->company();
        $setting = $this->config->settingFor($company);
        [$status, $reason] = $this->config->effectiveStatus($company);
        $certificate = $this->config->currentCertificate($company);

        return new SunatStatusResource([
            'environment' => $setting->environment->value,
            'environment_label' => $setting->environment->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'reason' => $reason,
            'can_issue' => $status === SunatStatus::Validated,
            'missing' => $this->config->missing($company),
            'sol_verified' => $setting->sol_verified_at !== null,
            'certificate_days_to_expire' => $certificate?->daysToExpire(),
        ]);
    }

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

    public function validate(Request $request): SunatSettingsResource
    {
        $company = $this->tenant->company();
        abort_unless($request->user()->hasCompanyRole(CompanyRole::CompanyAdmin), 403);
        $this->config->validate($company, $request->user());

        return $this->resource($company);
    }

    private function resource(Company $company): SunatSettingsResource
    {
        return new SunatSettingsResource(
            $this->config->settingFor($company),
            Certificate::with('uploader')->where('company_id', $company->id)->latest('id')->get(),
            $this->config->effectiveStatus($company),
        );
    }
}

<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CertificateStatus;
use App\Enums\SunatEnvironment;
use App\Enums\SunatStatus;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\SunatSetting;
use App\Models\User;
use App\Sunat\CertificateInspector;
use App\Sunat\Exceptions\InvalidCertificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuración SUNAT de la empresa (spec 004). Todo cambio de credencial o
 * certificado deja la configuración pendiente de validación (RF-012). La
 * auditoría nunca guarda valores secretos (RF-030).
 */
class SunatConfigService
{
    public function __construct(
        private AuditLogger $audit,
        private CertificateInspector $inspector,
    ) {}

    /** La configuración de la empresa, o una sin guardar «no configurada». */
    public function settingFor(Company $company): SunatSetting
    {
        return SunatSetting::firstOrNew(['company_id' => $company->id], [
            'environment' => SunatEnvironment::Beta,
            'status' => SunatStatus::NotConfigured,
        ]);
    }

    public function currentCertificate(Company $company): ?Certificate
    {
        return Certificate::where('company_id', $company->id)->where('status', CertificateStatus::Current)->latest('id')->first();
    }

    public function updateCredentials(Company $company, string $solUser, string $solPassword, User $actor): SunatSetting
    {
        $setting = $this->settingFor($company);
        $setting->fill([
            'sol_user' => strtoupper($solUser),
            'sol_password' => $solPassword,
            'sol_verified_at' => null,
            'status' => SunatStatus::Pending,
        ])->save();

        $this->audit->record('sunat.credentials_updated', $setting, ['sol_user' => self::mask($setting->sol_user)], actor: $actor);

        return $setting;
    }

    /** Valida el certificado y lo deja como vigente; el anterior pasa al historial. */
    public function uploadCertificate(Company $company, string $contents, ?string $password, User $actor): Certificate
    {
        try {
            $inspected = $this->inspector->inspect($contents, $password, $company->ruc);
        } catch (InvalidCertificate $e) {
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($company, $inspected, $password, $actor) {
            $previous = $this->currentCertificate($company);
            $previous?->update(['status' => CertificateStatus::Replaced, 'replaced_at' => now()]);

            $certificate = Certificate::create([
                'company_id' => $company->id,
                'pem' => $inspected->pem,
                'password' => $password,
                'subject' => $inspected->subject,
                'ruc' => $inspected->ruc,
                'serial_number' => $inspected->serialNumber,
                'valid_from' => $inspected->validFrom,
                'valid_to' => $inspected->validTo,
                'status' => CertificateStatus::Current,
                'uploaded_by' => $actor->id,
            ]);

            $setting = $this->settingFor($company);
            $setting->fill(['status' => SunatStatus::Pending])->save();

            $this->audit->record($previous ? 'sunat.certificate_replaced' : 'sunat.certificate_uploaded', $certificate, [
                'ruc' => $certificate->ruc,
                'valid_to' => $certificate->valid_to->toDateString(),
            ], actor: $actor);

            return $certificate;
        });
    }

    /** VENTAS01 → VE****01: identifica la cuenta sin revelarla entera. */
    public static function mask(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $length = mb_strlen($value);

        return $length <= 4 ? str_repeat('*', $length) : mb_substr($value, 0, 2).str_repeat('*', $length - 4).mb_substr($value, -2);
    }
}

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
use App\Sunat\SunatConnectionChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        private SunatConnectionChecker $connection,
    ) {}

    public const EXPIRED_CERTIFICATE = 'El certificado está vencido. Sube uno vigente.';

    /**
     * Lo que falta para poder validar (HU-2.2).
     *
     * @return list<string>
     */
    public function missing(Company $company): array
    {
        $setting = $this->settingFor($company);
        $missing = [];

        if ($setting->sol_user === null || $setting->sol_password === null) {
            $missing[] = 'Registra el usuario y la clave SOL.';
        }

        if ($this->currentCertificate($company) === null) {
            $missing[] = 'Sube el certificado digital.';
        }

        return $missing;
    }

    /**
     * Valida la configuración (HU-2): completa, certificado vigente y SUNAT
     * beta respondiendo. En beta la clave SOL real no se verifica (A-31).
     */
    public function validate(Company $company, User $actor): SunatSetting
    {
        if ($missing = $this->missing($company)) {
            throw ValidationException::withMessages(['configuration' => $missing]);
        }

        $setting = $this->settingFor($company);

        if ($this->currentCertificate($company)->isExpired()) {
            $setting->fill(['status' => SunatStatus::Error, 'last_validation_error' => self::EXPIRED_CERTIFICATE])->save();
            $this->audit->record('sunat.validated', $setting, ['result' => 'error'], actor: $actor);

            return $setting;
        }

        // SUNAT caída no es un error de la configuración (HU-2.3).
        if (! $this->connection->isAvailable()) {
            throw new HttpException(503, 'SUNAT no responde en este momento. Inténtalo más tarde.');
        }

        $setting->fill([
            'status' => SunatStatus::Validated,
            'last_validated_at' => now(),
            'last_validation_error' => null,
            'validated_by' => $actor->id,
        ])->save();

        $this->audit->record('sunat.validated', $setting, ['result' => 'validated'], actor: $actor);

        return $setting;
    }

    /**
     * Estado real en este momento: una configuración validada con el
     * certificado ya vencido está con error; una empresa desactivada, inactiva.
     *
     * @return array{SunatStatus, ?string}
     */
    public function effectiveStatus(Company $company): array
    {
        $setting = $this->settingFor($company);

        if (! $company->active) {
            return [SunatStatus::Inactive, 'La empresa está desactivada.'];
        }

        $certificate = $this->currentCertificate($company);

        if ($setting->status === SunatStatus::Validated && $certificate?->isExpired()) {
            return [SunatStatus::Error, self::EXPIRED_CERTIFICATE];
        }

        return [$setting->status, $setting->last_validation_error];
    }

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

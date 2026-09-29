<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use App\Models\SunatSetting;
use App\Services\SunatConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Configuración SUNAT para el administrador: solo metadatos, nunca la clave
 * SOL, el certificado ni su contraseña (HU-1.4, RF-002).
 *
 * @mixin SunatSetting
 */
class SunatSettingsResource extends ApiResource
{
    /** @param  Collection<int, Certificate>  $certificates */
    public function __construct(SunatSetting $setting, private Collection $certificates)
    {
        parent::__construct($setting);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $current = $this->certificates->firstWhere('status.value', 'current');

        return [
            'company_id' => $this->company_id,
            'environment' => $this->environment->value,
            'environment_label' => $this->environment->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sol_user_masked' => SunatConfigService::mask($this->sol_user),
            'has_sol_password' => $this->sol_password !== null,
            // En beta la clave real no se verifica contra SUNAT (A-31).
            'sol_verified' => $this->sol_verified_at !== null,
            'certificate' => $current ? $this->certificateData($current) : null,
            'certificates_history' => $this->certificates->map(fn (Certificate $c) => [
                ...$this->certificateData($c),
                'status' => $c->status->value,
                'replaced_at' => $c->replaced_at?->toIso8601String(),
            ])->values()->all(),
            'last_validated_at' => $this->last_validated_at?->toIso8601String(),
            'last_validation_error' => $this->last_validation_error,
        ];
    }

    /** @return array<string, mixed> */
    private function certificateData(Certificate $certificate): array
    {
        return [
            'subject' => $certificate->subject,
            'ruc' => $certificate->ruc,
            'valid_from' => $certificate->valid_from->toIso8601String(),
            'valid_to' => $certificate->valid_to->toIso8601String(),
            'days_to_expire' => $certificate->daysToExpire(),
            'uploaded_by' => $certificate->uploader?->name,
        ];
    }
}

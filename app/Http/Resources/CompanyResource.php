<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Datos generales de la empresa. Nunca incluye secretos (HU-4.4).
 *
 * @mixin Company
 */
class CompanyResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $main = $this->mainEstablishment;

        return [
            'id' => $this->id,
            'ruc' => $this->ruc,
            'razon_social' => $this->razon_social,
            'nombre_comercial' => $this->nombre_comercial,
            'person_type' => $this->person_type->value,
            'tax_regime' => $this->tax_regime->value,
            'email' => $this->email,
            'phone' => $this->phone,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'active' => $this->active,
            'expiry_warning_days' => $this->expiry_warning_days,
            'fiscal_address' => $main ? [
                'address' => $main->address,
                'ubigeo' => $main->ubigeo,
                'district' => $main->district?->info_busqueda,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

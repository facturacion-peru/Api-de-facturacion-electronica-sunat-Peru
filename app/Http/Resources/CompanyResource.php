<?php

namespace App\Http\Resources;

use App\Establishments\EstablishmentAccess;
use App\Images\CompanyLogo;
use App\Models\Company;
use App\Models\Establishment;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

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
            'logo_url' => app(CompanyLogo::class)->url($this->logo_path),
            'active' => $this->active,
            'expiry_warning_days' => $this->expiry_warning_days,
            'fiscal_address' => $main ? [
                'address' => $main->address,
                'ubigeo' => $main->ubigeo,
                'district' => $main->district?->info_busqueda,
            ] : null,
            'active_establishments_count' => Establishment::withoutTenancy()->where('company_id', $this->id)->where('active', true)->count(),
            // Los permitidos a quien pregunta (spec 017).
            'establishments' => $request->user()
                ? app(TenantContext::class)->run($this->resource, fn () => EstablishmentResource::collection(app(EstablishmentAccess::class)->allowedForDisplay($request->user())))
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

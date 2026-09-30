<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Platform\CompanySummaries;
use Illuminate\Http\Request;

/**
 * Ficha de una empresa en el panel de la plataforma (HU-1.3, A-37).
 *
 * @mixin Company
 */
class PlatformCompanyDetailResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summaries = app(CompanySummaries::class);
        $main = $this->mainEstablishment;

        return [
            ...PlatformCompanyResource::make($this->resource)->toArray($request),
            'person_type' => $this->person_type->value,
            'tax_regime' => $this->tax_regime->value,
            'tax_regime_label' => $this->tax_regime->label(),
            'email' => $this->email,
            'phone' => $this->phone,
            'fiscal_address' => $main ? [
                'address' => $main->address,
                'ubigeo' => $main->ubigeo,
                'district' => $main->district?->info_busqueda,
            ] : null,
            'admin' => $summaries->admin($this->resource),
            'active_users' => $summaries->activeUsers($this->resource),
            'last_activity_at' => $summaries->lastActivityAt($this->resource),
        ];
    }
}

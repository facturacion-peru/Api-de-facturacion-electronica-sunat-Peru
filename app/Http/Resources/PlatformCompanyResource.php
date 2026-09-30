<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Platform\CompanySummaries;
use Illuminate\Http\Request;

/**
 * Empresa en la lista del panel de la plataforma (spec 006, A-37): lista
 * blanca de metadatos y contadores. Nunca secretos ni datos de negocio.
 *
 * @mixin Company
 */
class PlatformCompanyResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        [$status, $reason] = app(CompanySummaries::class)->sunatStatus($this->resource);

        return [
            'id' => $this->id,
            'ruc' => $this->ruc,
            'razon_social' => $this->razon_social,
            'nombre_comercial' => $this->nombre_comercial,
            'active' => $this->active,
            'sunat_status' => $status->value,
            'sunat_status_label' => $status->label(),
            'sunat_reason' => $reason,
            'users_count' => (int) $this->users_count,
            'pending_documents' => (int) $this->pending_documents,
            'rejected_documents' => (int) $this->rejected_documents,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Enums\CompanyRole;
use App\Models\ProductLot;
use Illuminate\Http\Request;

/**
 * Lote con su saldo. El costo solo para el administrador (RF-022).
 *
 * @mixin ProductLot
 */
class LotResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_number' => $this->lot_number,
            'received_at' => $this->received_at->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'expired' => $this->isExpired(),
            'initial_quantity' => $this->initial_quantity,
            'remaining_quantity' => $this->remaining_quantity,
            'unit_cost' => $this->when(
                $request->user()?->hasCompanyRole(CompanyRole::CompanyAdmin) === true,
                fn () => $this->unit_cost,
            ),
            'reference' => $this->reference,
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

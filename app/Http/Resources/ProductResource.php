<?php

namespace App\Http\Resources;

use App\Enums\CompanyRole;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Producto con su stock. El costo solo se incluye para el administrador
 * de empresa (RF-022, A-27).
 *
 * @mixin Product
 */
class ProductResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tracksStock = $this->type->tracksStock();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'unit' => $this->unit->value,
            'sale_price' => $this->sale_price,
            'igv_affectation' => $this->igv_affectation->value,
            'min_stock' => $this->min_stock,
            'tracks_expiry' => $this->tracks_expiry,
            'active' => $this->active,
            'stock' => $tracksStock ? $this->stock() : null,
            'available_stock' => $tracksStock ? $this->availableStock() : null,
            'last_unit_cost' => $this->when(
                $request->user()?->hasCompanyRole(CompanyRole::CompanyAdmin) === true,
                fn () => $this->lots()->whereNotNull('unit_cost')->latest('received_at')->latest('id')->value('unit_cost'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

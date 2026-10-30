<?php

namespace App\Http\Resources;

use App\Enums\CompanyRole;
use App\Establishments\EstablishmentAccess;
use App\Images\ImageStore;
use App\Models\Product;
use App\Support\Decimal;
use Illuminate\Http\Request;

/**
 * Producto con su stock. El costo solo se incluye para el administrador
 * de empresa (RF-022, A-27).
 *
 * @mixin Product
 */
class ProductResource extends ApiResource
{
    private bool $byEstablishment = false;

    /** Agrega el stock de cada local que el usuario puede leer (spec 017): ficha y traslado. */
    public function withStockByEstablishment(): static
    {
        $this->byEstablishment = true;

        return $this;
    }

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
            'image' => $this->image(),
            'stock' => $tracksStock ? $this->stock() : null,
            'available_stock' => $tracksStock ? $this->availableStock() : null,
            'stock_by_establishment' => $this->when($this->byEstablishment, fn () => $tracksStock ? $this->stockByEstablishment($request) : null),
            'last_unit_cost' => $this->when(
                $request->user()?->hasCompanyRole(CompanyRole::CompanyAdmin) === true,
                fn () => $this->lots()->whereNotNull('unit_cost')->latest('received_at')->latest('id')->value('unit_cost'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return list<array{establishment_id: int, stock: string, available_stock: string}> */
    private function stockByEstablishment(Request $request): array
    {
        $lots = $this->lots()->get(['establishment_id', 'remaining_quantity', 'expires_at'])->groupBy('establishment_id');

        return collect(app(EstablishmentAccess::class)->readable($request->user()))->map(fn (int $id) => [
            'establishment_id' => $id,
            'stock' => Decimal::sum(($lots[$id] ?? collect())->pluck('remaining_quantity')),
            'available_stock' => Decimal::sum(($lots[$id] ?? collect())->reject(fn ($lot) => $lot->isExpired())->pluck('remaining_quantity')),
        ])->all();
    }

    /**
     * Foto en dos tamaños (spec 016). Las direcciones se arman sin consultar
     * el disco: el GET nunca depende de S3 (RF-010).
     *
     * @return array{url: string, thumb_url: string}|null
     */
    private function image(): ?array
    {
        if ($this->image_key === null) {
            return null;
        }

        $store = app(ImageStore::class);
        [$large, $thumb] = ImageStore::productPaths($this->image_key);

        return ['url' => $store->url($large), 'thumb_url' => $store->url($thumb)];
    }
}

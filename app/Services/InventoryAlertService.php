<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Http\Resources\EstablishmentRef;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Support\Decimal;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Alertas de inventario de la empresa del contexto (spec 002, HU-5.2 y HU-5.3):
 * stock bajo, lotes por vencer y lotes vencidos. Las usan el listado de
 * alertas y el panel de inicio (spec 011).
 */
class InventoryAlertService
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * Alertas de los locales pedidos (spec 017). El mínimo es del producto y
     * se compara con el stock de cada local activo: un producto puede salir
     * una vez por local. Los lotes de un local inactivo siguen avisando.
     *
     * @param  list<int>  $establishmentIds
     * @return array{low_stock: Collection<int, array<string, mixed>>, expiring: Collection<int, array<string, mixed>>, expired: Collection<int, array<string, mixed>>}
     */
    public function alerts(array $establishmentIds): array
    {
        $establishments = Establishment::active()->whereIn('id', $establishmentIds)->orderBy('code')->get();
        $products = Product::query()
            ->where('active', true)
            ->where('type', ProductType::Good)
            ->whereNotNull('min_stock')
            ->orderBy('name')
            ->get();
        $available = ProductLot::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->whereIn('establishment_id', $establishments->pluck('id'))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()))
            ->get(['product_id', 'establishment_id', 'remaining_quantity'])
            ->groupBy(fn (ProductLot $lot) => $lot->product_id.'-'.$lot->establishment_id)
            ->map(fn ($lots) => Decimal::sum($lots->pluck('remaining_quantity')));

        $lowStock = $products->flatMap(fn (Product $p) => $establishments->map(fn (Establishment $e) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'establishment' => EstablishmentRef::of($e),
            'available_stock' => $available[$p->id.'-'.$e->id] ?? '0.000',
            'min_stock' => $p->min_stock,
        ]))->filter(fn (array $alert) => bccomp($alert['available_stock'], $alert['min_stock'], 3) <= 0)->values();

        $lots = fn () => ProductLot::query()->with(['product', 'establishment'])->whereIn('establishment_id', $establishmentIds)
            ->where('remaining_quantity', '>', 0)->orderBy('expires_at');
        $days = $this->warningDays();

        return [
            'low_stock' => $lowStock,
            'expiring' => $lots()->whereBetween('expires_at', [today(), today()->addDays($days)])->get()->map($this->lotAlert(...)),
            'expired' => $lots()->where('expires_at', '<', today())->get()->map($this->lotAlert(...)),
        ];
    }

    /**
     * Total de alertas: stock bajo, por vencer y vencidos.
     *
     * @param  list<int>  $establishmentIds
     */
    public function count(array $establishmentIds): int
    {
        return collect($this->alerts($establishmentIds))->sum(fn (Collection $items) => $items->count());
    }

    public function warningDays(): int
    {
        return $this->tenant->company()->expiry_warning_days;
    }

    /** @return array<string, mixed> */
    private function lotAlert(ProductLot $lot): array
    {
        return [
            'id' => $lot->id,
            'lot_number' => $lot->lot_number,
            'establishment' => EstablishmentRef::of($lot->establishment),
            'product' => ['id' => $lot->product->id, 'code' => $lot->product->code, 'name' => $lot->product->name],
            'expires_at' => $lot->expires_at->toDateString(),
            'days_left' => (int) today()->diffInDays($lot->expires_at, false),
            'remaining_quantity' => $lot->remaining_quantity,
        ];
    }
}

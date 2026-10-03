<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductLot;
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

    /** @return array{low_stock: Collection<int, array<string, mixed>>, expiring: Collection<int, array<string, mixed>>, expired: Collection<int, array<string, mixed>>} */
    public function alerts(): array
    {
        $lowStock = Product::query()
            ->withStock()
            ->where('active', true)
            ->where('type', ProductType::Good)
            ->whereNotNull('min_stock')
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $p) => bccomp($p->availableStock(), $p->min_stock, 3) <= 0)
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'available_stock' => $p->availableStock(),
                'min_stock' => $p->min_stock,
            ])
            ->values();

        $lots = fn () => ProductLot::query()->with('product')->where('remaining_quantity', '>', 0)->orderBy('expires_at');
        $days = $this->warningDays();

        return [
            'low_stock' => $lowStock,
            'expiring' => $lots()->whereBetween('expires_at', [today(), today()->addDays($days)])->get()->map($this->lotAlert(...)),
            'expired' => $lots()->where('expires_at', '<', today())->get()->map($this->lotAlert(...)),
        ];
    }

    /** Total de alertas: stock bajo, por vencer y vencidos. */
    public function count(): int
    {
        return collect($this->alerts())->sum(fn (Collection $items) => $items->count());
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
            'product' => ['id' => $lot->product->id, 'code' => $lot->product->code, 'name' => $lot->product->name],
            'expires_at' => $lot->expires_at->toDateString(),
            'days_left' => (int) today()->diffInDays($lot->expires_at, false),
            'remaining_quantity' => $lot->remaining_quantity,
        ];
    }
}

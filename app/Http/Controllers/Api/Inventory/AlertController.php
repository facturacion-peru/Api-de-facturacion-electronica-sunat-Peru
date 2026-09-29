<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductLot;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Alertas de inventario de la empresa (HU-5.2, HU-5.3, RF-021). */
class AlertController extends Controller
{
    public function index(TenantContext $tenant): JsonResponse
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
        $days = $tenant->company()->expiry_warning_days;

        return response()->json([
            'success' => true,
            'data' => [
                'low_stock' => $lowStock,
                'expiring' => $lots()->whereBetween('expires_at', [today(), today()->addDays($days)])->get()->map($this->lotAlert(...)),
                'expired' => $lots()->where('expires_at', '<', today())->get()->map($this->lotAlert(...)),
            ],
            'meta' => ['expiry_warning_days' => $days],
        ]);
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

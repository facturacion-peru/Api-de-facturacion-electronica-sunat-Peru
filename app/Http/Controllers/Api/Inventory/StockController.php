<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockEntryRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\LotResource;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lotes y movimientos de stock de un producto (HU-2 a HU-5). */
class StockController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    /** Lotes en orden de salida (FEFO/FIFO); por defecto solo los que tienen saldo. */
    public function lots(Request $request, Product $product): ApiCollection
    {
        $lots = $product->lots()
            ->with('creator')
            ->unless($request->boolean('include_empty'), fn ($q) => $q->where('remaining_quantity', '>', 0))
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        return LotResource::collection($lots);
    }

    public function storeEntry(StoreStockEntryRequest $request, Product $product): JsonResponse
    {
        $lot = $this->inventory->registerEntry($product, $request->validated(), $request->user());

        return LotResource::make($lot->load('creator'))
            ->additional(['product_stock' => $this->inventory->balance($product)])
            ->response()
            ->setStatusCode(201);
    }
}

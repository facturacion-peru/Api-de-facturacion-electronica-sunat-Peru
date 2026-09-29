<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Enums\AdjustmentReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\ReverseMovementRequest;
use App\Http\Requests\Inventory\StoreStockEntryRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\LotResource;
use App\Http\Resources\MovementResource;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
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

    /** Historial del producto, del más reciente al más antiguo (HU-5.1). */
    public function movements(Product $product): ApiCollection
    {
        return MovementResource::collection(
            $product->movements()
                ->with(['lot', 'creator', 'reversal'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function storeEntry(StoreStockEntryRequest $request, Product $product): JsonResponse
    {
        $lot = $this->inventory->registerEntry($product, $request->validated(), $request->user());

        return LotResource::make($lot->load('creator'))
            ->additional(['product_stock' => $this->inventory->balance($product)])
            ->response()
            ->setStatusCode(201);
    }

    public function adjust(AdjustStockRequest $request, ProductLot $lot): JsonResponse
    {
        $movement = $this->inventory->adjust(
            $lot,
            (string) $request->validated('quantity'),
            AdjustmentReason::from($request->validated('reason')),
            $request->validated('note'),
            $request->user(),
        );

        return MovementResource::make($movement->load(['lot', 'creator']))->response()->setStatusCode(201);
    }

    public function reverse(ReverseMovementRequest $request, InventoryMovement $movement): JsonResponse
    {
        $reversal = $this->inventory->reverse(
            $movement,
            AdjustmentReason::from($request->validated('reason')),
            $request->validated('note'),
            $request->user(),
        );

        return MovementResource::make($reversal->load(['lot', 'creator']))->response()->setStatusCode(201);
    }
}

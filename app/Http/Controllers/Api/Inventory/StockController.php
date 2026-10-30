<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Enums\AdjustmentReason;
use App\Establishments\EstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\IndexLotRequest;
use App\Http\Requests\Inventory\IndexMovementRequest;
use App\Http\Requests\Inventory\ReverseMovementRequest;
use App\Http\Requests\Inventory\StoreStockEntryRequest;
use App\Http\Requests\Inventory\StoreTransferRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\LotResource;
use App\Http\Resources\MovementResource;
use App\Http\Resources\ProductResource;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

/** Lotes y movimientos de stock de un producto (HU-2 a HU-5). */
class StockController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    /** Lotes en orden de salida (FEFO/FIFO); por defecto solo los que tienen saldo. */
    public function lots(IndexLotRequest $request, Product $product, EstablishmentAccess $access): ApiCollection
    {
        $lots = $product->lots()
            ->with(['creator', 'establishment'])
            ->whereIn('establishment_id', $access->filter($request->user(), $request->integer('establishment_id') ?: null))
            ->unless($request->boolean('include_empty'), fn ($q) => $q->where('remaining_quantity', '>', 0))
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        return LotResource::collection($lots);
    }

    /** Historial del producto, del más reciente al más antiguo (HU-5.1). */
    public function movements(IndexMovementRequest $request, Product $product, EstablishmentAccess $access): ApiCollection
    {
        return MovementResource::collection(
            $product->movements()
                ->with(['lot', 'creator', 'reversal', 'establishment', 'source'])
                ->whereIn('establishment_id', $access->filter($request->user(), $request->integer('establishment_id') ?: null))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function storeEntry(StoreStockEntryRequest $request, Product $product, EstablishmentAccess $access): JsonResponse
    {
        $establishment = $access->resolve($request->user(), $request->integer('establishment_id') ?: null);
        $lot = $this->inventory->registerEntry($product, $establishment, collect($request->validated())->except('establishment_id')->all(), $request->user());

        return LotResource::make($lot->load(['creator', 'establishment']))
            ->additional(['product_stock' => $this->inventory->balance($product, $establishment)])
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

    /**
     * Traslado entre locales (spec 017, HU-3). Se puede sacar stock de un
     * local inactivo, pero solo se traslada a uno activo.
     */
    public function storeTransfer(StoreTransferRequest $request, Product $product, EstablishmentAccess $access): JsonResponse
    {
        $user = $request->user();
        [$fromId] = $access->filter($user, (int) $request->validated('from_establishment_id'), 'from_establishment_id');
        $to = $access->resolve($user, (int) $request->validated('to_establishment_id'), 'to_establishment_id');

        $this->inventory->transfer($product, Establishment::findOrFail($fromId), $to, (string) $request->validated('quantity'), $request->validated('note'), $user);

        return ProductResource::make($product->fresh())->withStockByEstablishment()->response()->setStatusCode(201);
    }
}

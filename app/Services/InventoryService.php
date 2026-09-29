<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\MovementType;
use App\Inventory\Exceptions\InsufficientStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Único punto de escritura del stock (plan 002).
 *
 * Toda operación bloquea primero la fila del producto: así las escrituras de
 * stock de un mismo producto se serializan siempre en el mismo orden, sin
 * carreras ni deadlocks (CE-002). Las cantidades se calculan con bcmath.
 */
class InventoryService
{
    public const SCALE = 3;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Entrada de mercadería por lote (HU-2).
     *
     * @param  array{quantity: string, received_at?: ?string, lot_number?: ?string, expires_at?: ?string, unit_cost?: ?string, reference?: ?string}  $data
     */
    public function registerEntry(Product $product, array $data, User $actor): ProductLot
    {
        if (! $product->type->tracksStock()) {
            throw ValidationException::withMessages(['product' => 'Los servicios no tienen stock.']);
        }

        return DB::transaction(function () use ($product, $data, $actor) {
            $this->lock($product);
            $quantity = $this->normalize($data['quantity']);

            $lot = ProductLot::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                // Provisional hasta conocer el id (número automático, A-13).
                'lot_number' => $data['lot_number'] ?? 'L-pendiente-'.uniqid(),
                'received_at' => $data['received_at'] ?? today(),
                'expires_at' => $data['expires_at'] ?? null,
                'initial_quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'unit_cost' => $data['unit_cost'] ?? null,
                'reference' => $data['reference'] ?? null,
                'created_by' => $actor->id,
            ]);

            if (empty($data['lot_number'])) {
                $lot->forceFill(['lot_number' => 'L-'.$lot->received_at->format('Ymd').'-'.$lot->id])->save();
            }

            $this->recordMovement($lot, MovementType::Entry, $quantity, $actor);

            $this->audit->record('inventory.entry', $lot, [
                'product' => $product->code,
                'lot_number' => $lot->lot_number,
                'quantity' => $quantity,
            ], actor: $actor);

            return $lot;
        });
    }

    /**
     * Descuenta stock por una venta (HU-3): FEFO si el producto controla
     * vencimiento, FIFO si no; nunca de lotes vencidos; un movimiento por
     * lote. Para las specs 003 y 005.
     *
     * Debe llamarse dentro de la transacción de la venta, para que venta y
     * descuento se confirmen o fallen juntos (RF-017).
     *
     * @return Collection<int, InventoryMovement>
     *
     * @throws InsufficientStock
     */
    public function consume(Product $product, string $quantity, ?Model $source, User $actor): Collection
    {
        if (bccomp($quantity, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('La cantidad a descontar debe ser mayor que cero.');
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('consume() debe ejecutarse dentro de la transacción de la venta (RF-017).');
        }

        if (! $product->type->tracksStock()) {
            return collect();
        }

        $this->lock($product);
        $pending = $this->normalize($quantity);
        $lots = $this->sellableLots($product);
        $available = $this->normalize((string) $lots->reduce(fn (string $sum, ProductLot $lot) => bcadd($sum, $lot->remaining_quantity, self::SCALE), '0'));

        if (bccomp($available, $pending, self::SCALE) < 0) {
            throw new InsufficientStock($product, $pending, $available);
        }

        $movements = collect();

        foreach ($lots as $lot) {
            if (bccomp($pending, '0', self::SCALE) === 0) {
                break;
            }

            $take = bccomp($lot->remaining_quantity, $pending, self::SCALE) < 0 ? $lot->remaining_quantity : $pending;
            $lot->remaining_quantity = bcsub($lot->remaining_quantity, $take, self::SCALE);
            $lot->save();
            $pending = bcsub($pending, $take, self::SCALE);

            $movements->push($this->recordMovement($lot, MovementType::Sale, '-'.$take, $actor, [
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]));
        }

        return $movements;
    }

    /** Stock físico del producto (suma de saldos de sus lotes). */
    public function balance(Product $product): string
    {
        return $this->normalize((string) ProductLot::withoutTenancy()->where('product_id', $product->id)->sum('remaining_quantity'));
    }

    /**
     * Lotes con saldo y no vencidos, en orden de salida (A-14). Con
     * vencimiento, los lotes sin fecha salen al final.
     *
     * @return Collection<int, ProductLot>
     */
    private function sellableLots(Product $product): Collection
    {
        return ProductLot::withoutTenancy()
            ->where('product_id', $product->id)
            ->where('remaining_quantity', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()))
            ->when($product->tracks_expiry, fn ($q) => $q->orderByRaw('expires_at IS NULL')->orderBy('expires_at'))
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Bloquea la fila del producto hasta el final de la transacción. */
    private function lock(Product $product): void
    {
        Product::withoutTenancy()->whereKey($product->id)->lockForUpdate()->first();
    }

    /**
     * Registra el movimiento con los saldos resultantes. El saldo del lote ya
     * debe estar actualizado cuando se llama.
     */
    private function recordMovement(
        ProductLot $lot,
        MovementType $type,
        string $quantity,
        User $actor,
        array $extra = [],
    ): InventoryMovement {
        return InventoryMovement::create([
            'company_id' => $lot->company_id,
            'product_id' => $lot->product_id,
            'lot_id' => $lot->id,
            'type' => $type,
            'quantity' => $quantity,
            'lot_balance_after' => $lot->remaining_quantity,
            'product_balance_after' => $this->balance($lot->product()->withoutGlobalScopes()->first()),
            'created_by' => $actor->id,
            ...$extra,
        ]);
    }

    private function normalize(string $quantity): string
    {
        return bcadd($quantity, '0', self::SCALE);
    }
}

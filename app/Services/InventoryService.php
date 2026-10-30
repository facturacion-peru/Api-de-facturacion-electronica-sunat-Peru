<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Inventory\Exceptions\InsufficientStock;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\StockTransfer;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Decimal;
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
    public function registerEntry(Product $product, Establishment $establishment, array $data, User $actor): ProductLot
    {
        if (! $product->type->tracksStock()) {
            throw ValidationException::withMessages(['product' => 'Los servicios no tienen stock.']);
        }

        return DB::transaction(function () use ($product, $establishment, $data, $actor) {
            $this->lock($product);
            $quantity = $this->normalize($data['quantity']);

            $lot = ProductLot::create([
                'company_id' => $product->company_id,
                'establishment_id' => $establishment->id,
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
                'establishment' => $establishment->code,
                'lot_number' => $lot->lot_number,
                'quantity' => $quantity,
            ], actor: $actor);

            return $lot;
        });
    }

    /**
     * Descuenta stock por una venta (HU-3) en el local donde se vende (spec
     * 017): FEFO si el producto controla vencimiento, FIFO si no; nunca de
     * lotes vencidos; un movimiento por lote. Para las specs 003 y 005.
     *
     * Debe llamarse dentro de la transacción de la venta, para que venta y
     * descuento se confirmen o fallen juntos (RF-017).
     *
     * @return Collection<int, InventoryMovement>
     *
     * @throws InsufficientStock
     */
    public function consume(Product $product, Establishment $establishment, string $quantity, ?Model $source, User $actor): Collection
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
        $lots = $this->sellableLots($product, $establishment);
        $available = Decimal::sum($lots->pluck('remaining_quantity'));

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

    /** Ajuste por conteo, merma, daño… Nunca deja el lote en negativo (HU-4.1, HU-4.3). */
    public function adjust(ProductLot $lot, string $quantity, AdjustmentReason $reason, ?string $note, User $actor): InventoryMovement
    {
        return DB::transaction(function () use ($lot, $quantity, $reason, $note, $actor) {
            $lot = $this->lockLot($lot);
            $quantity = $this->normalize($quantity);
            $newBalance = bcadd($lot->remaining_quantity, $quantity, self::SCALE);

            if (bccomp($newBalance, '0', self::SCALE) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "El lote quedaría en negativo: su saldo es {$lot->remaining_quantity}.",
                ]);
            }

            $lot->remaining_quantity = $newBalance;
            $lot->save();

            $movement = $this->recordMovement($lot, MovementType::Adjustment, $quantity, $actor, [
                'reason' => $reason,
                'note' => $note,
            ]);

            $this->audit->record('inventory.adjustment', $movement, [
                'lot_number' => $lot->lot_number,
                'quantity' => $quantity,
                'reason' => $reason->value,
            ], actor: $actor);

            return $movement;
        });
    }

    /**
     * Corrige un movimiento con otro inverso enlazado; el original nunca se
     * toca (HU-4.2, RF-016). Una sola vez por movimiento, y nunca sobre una
     * reversión.
     *
     * Las ventas de un ticket solo se revierten anulando el ticket
     * ($fromSource = true desde TicketService), para que ticket y stock no
     * queden desalineados (plan 003).
     */
    public function reverse(InventoryMovement $movement, AdjustmentReason $reason, ?string $note, User $actor, bool $fromSource = false): InventoryMovement
    {
        return DB::transaction(function () use ($movement, $reason, $note, $actor, $fromSource) {
            $lot = $this->lockLot($movement->lot()->withoutGlobalScopes()->firstOrFail());

            if (! $fromSource && $movement->source_type === (new Ticket)->getMorphClass()) {
                $ticket = Ticket::withoutTenancy()->find($movement->source_id);

                throw ValidationException::withMessages([
                    'movement' => "Esta venta pertenece al ticket {$ticket?->display_number}: anúlalo para devolver el stock.",
                ]);
            }

            // Ventas y reposiciones de comprobantes: solo con una nota de crédito o
            // descartando el rechazado (spec 007), para no descuadrar stock y comprobante.
            if (! $fromSource && $movement->source_type === (new SalesDocument)->getMorphClass()) {
                $document = SalesDocument::withoutTenancy()->find($movement->source_id);

                throw ValidationException::withMessages([
                    'movement' => "Este movimiento pertenece al comprobante {$document?->display_number}: corrígelo con una nota de crédito.",
                ]);
            }

            // Un traslado se corrige con otro traslado (spec 017): revertir una
            // sola mitad descuadraría los dos locales.
            if ($movement->type->isTransfer()) {
                throw ValidationException::withMessages(['movement' => 'Este movimiento es de un traslado: corrígelo con otro traslado.']);
            }

            if ($movement->type === MovementType::Reversal) {
                throw ValidationException::withMessages(['movement' => 'No se puede revertir una reversión.']);
            }

            if (InventoryMovement::withoutTenancy()->where('reverses_id', $movement->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este movimiento ya fue revertido.']);
            }

            $inverse = bcmul($movement->quantity, '-1', self::SCALE);
            $newBalance = bcadd($lot->remaining_quantity, $inverse, self::SCALE);

            if (bccomp($newBalance, '0', self::SCALE) < 0) {
                throw ValidationException::withMessages([
                    'movement' => "No se puede revertir: el lote ya no tiene el saldo de este movimiento (saldo {$lot->remaining_quantity}).",
                ]);
            }

            $lot->remaining_quantity = $newBalance;
            $lot->save();

            $reversal = $this->recordMovement($lot, MovementType::Reversal, $inverse, $actor, [
                'reason' => $reason,
                'note' => $note,
                'reverses_id' => $movement->id,
            ]);

            $this->audit->record('inventory.reversal', $reversal, [
                'reverses' => $movement->id,
                'type' => $movement->type->value,
                'quantity' => $inverse,
                'reason' => $reason->value,
            ], actor: $actor);

            return $reversal;
        });
    }

    /**
     * Repone stock por una nota de crédito (spec 007, A-40): en los lotes de
     * los que salió la venta del comprobante original, en orden inverso, sin
     * superar en cada lote lo que salió menos lo ya repuesto por sus notas.
     * Debe llamarse dentro de la transacción de la nota.
     *
     * @return Collection<int, InventoryMovement>
     */
    public function restock(Product $product, string $quantity, SalesDocument $original, SalesDocument $note, User $actor): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('restock() debe ejecutarse dentro de la transacción de la nota.');
        }

        if (! $product->type->tracksStock()) {
            return collect();
        }

        $this->lock($product);
        $morph = $original->getMorphClass();
        $noteIds = SalesDocument::withoutTenancy()->where('reference_document_id', $original->id)->pluck('id');

        $sold = InventoryMovement::withoutTenancy()
            ->where('product_id', $product->id)->where('type', MovementType::Sale)
            ->where('source_type', $morph)->where('source_id', $original->id)
            ->whereDoesntHave('reversal')
            ->orderByDesc('id')->get()
            ->groupBy('lot_id')->map(fn ($m) => Decimal::sum($m->map(fn ($x) => bcmul($x->quantity, '-1', self::SCALE))));

        $returned = InventoryMovement::withoutTenancy()
            ->where('product_id', $product->id)->where('type', MovementType::Return)
            ->where('source_type', $morph)->whereIn('source_id', $noteIds)
            ->whereDoesntHave('reversal')
            ->get()->groupBy('lot_id')->map(fn ($m) => Decimal::sum($m->pluck('quantity')));

        $pending = $this->normalize($quantity);
        $movements = collect();

        foreach ($sold as $lotId => $soldFromLot) {
            if (bccomp($pending, '0', self::SCALE) === 0) {
                break;
            }

            $room = bcsub($soldFromLot, $returned[$lotId] ?? '0', self::SCALE);
            if (bccomp($room, '0', self::SCALE) <= 0) {
                continue;
            }

            $take = bccomp($room, $pending, self::SCALE) < 0 ? $room : $pending;
            $lot = $this->lockLot(ProductLot::withoutTenancy()->findOrFail($lotId));
            $lot->remaining_quantity = bcadd($lot->remaining_quantity, $take, self::SCALE);
            $lot->save();
            $pending = bcsub($pending, $take, self::SCALE);

            $movements->push($this->recordMovement($lot, MovementType::Return, $take, $actor, [
                'source_type' => $note->getMorphClass(),
                'source_id' => $note->getKey(),
            ]));
        }

        if (bccomp($pending, '0', self::SCALE) > 0) {
            throw new InvalidArgumentException('No se puede reponer más de lo que salió con el comprobante.');
        }

        return $movements;
    }

    /**
     * Traslado entre locales (spec 017, HU-3): sale FEFO de los lotes no
     * vencidos del origen, como una venta, y entra en el destino en el lote
     * del mismo número, con las mismas fechas y costo. Todo o nada; el bloqueo
     * del producto ordena los traslados cruzados sin deadlocks (CE-004).
     *
     * @throws InsufficientStock
     */
    public function transfer(Product $product, Establishment $from, Establishment $to, string $quantity, ?string $note, User $actor): StockTransfer
    {
        if (! $product->type->tracksStock()) {
            throw ValidationException::withMessages(['product' => 'Los servicios no tienen stock.']);
        }

        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_establishment_id' => 'El destino debe ser otro local.']);
        }

        return DB::transaction(function () use ($product, $from, $to, $quantity, $note, $actor) {
            $this->lock($product);
            $pending = $this->normalize($quantity);
            $lots = $this->sellableLots($product, $from);
            $available = Decimal::sum($lots->pluck('remaining_quantity'));

            if (bccomp($available, $pending, self::SCALE) < 0) {
                throw new InsufficientStock($product, $pending, $available);
            }

            $transfer = StockTransfer::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'from_establishment_id' => $from->id,
                'to_establishment_id' => $to->id,
                'quantity' => $pending,
                'note' => $note,
                'created_by' => $actor->id,
            ]);
            $source = ['source_type' => $transfer->getMorphClass(), 'source_id' => $transfer->id];

            foreach ($lots as $lot) {
                if (bccomp($pending, '0', self::SCALE) === 0) {
                    break;
                }

                $take = bccomp($lot->remaining_quantity, $pending, self::SCALE) < 0 ? $lot->remaining_quantity : $pending;
                $lot->remaining_quantity = bcsub($lot->remaining_quantity, $take, self::SCALE);
                $lot->save();
                $pending = bcsub($pending, $take, self::SCALE);
                $this->recordMovement($lot, MovementType::TransferOut, '-'.$take, $actor, $source);

                $arrival = $this->arrivalLot($lot, $to, $take, $actor);
                $this->recordMovement($arrival, MovementType::TransferIn, $take, $actor, $source);
            }

            $this->audit->record('inventory.transfer', $transfer, [
                'product' => $product->code,
                'from' => $from->code,
                'to' => $to->code,
                'quantity' => $transfer->quantity,
            ], actor: $actor);

            return $transfer;
        });
    }

    /** Stock físico del producto en un local, o en todos (suma de saldos de sus lotes). */
    public function balance(Product $product, ?Establishment $establishment = null): string
    {
        // Suma en PHP con bcmath: exacta también en SQLite, donde SUM() usa flotantes.
        return Decimal::sum(ProductLot::withoutTenancy()->where('product_id', $product->id)
            ->when($establishment, fn ($q) => $q->where('establishment_id', $establishment->id))
            ->pluck('remaining_quantity'));
    }

    /**
     * Lotes con saldo y no vencidos, en orden de salida (A-14). Con
     * vencimiento, los lotes sin fecha salen al final.
     *
     * @return Collection<int, ProductLot>
     */
    private function sellableLots(Product $product, Establishment $establishment): Collection
    {
        return ProductLot::withoutTenancy()
            ->where('product_id', $product->id)
            ->where('establishment_id', $establishment->id)
            ->where('remaining_quantity', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()))
            ->when($product->tracks_expiry, fn ($q) => $q->orderByRaw('expires_at IS NULL')->orderBy('expires_at'))
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Lote del destino con el mismo número (lo crea si no existe) y la cantidad ya sumada. */
    private function arrivalLot(ProductLot $origin, Establishment $to, string $quantity, User $actor): ProductLot
    {
        $lot = ProductLot::withoutTenancy()
            ->where('product_id', $origin->product_id)->where('establishment_id', $to->id)->where('lot_number', $origin->lot_number)
            ->lockForUpdate()->first();

        if ($lot === null) {
            return ProductLot::create([
                'company_id' => $origin->company_id,
                'establishment_id' => $to->id,
                'product_id' => $origin->product_id,
                'lot_number' => $origin->lot_number,
                'received_at' => $origin->received_at,
                'expires_at' => $origin->expires_at,
                'initial_quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'unit_cost' => $origin->unit_cost,
                'reference' => $origin->reference,
                'created_by' => $actor->id,
            ]);
        }

        $lot->remaining_quantity = bcadd($lot->remaining_quantity, $quantity, self::SCALE);
        $lot->save();

        return $lot;
    }

    /** Bloquea el producto del lote y devuelve el lote releído y bloqueado. */
    private function lockLot(ProductLot $lot): ProductLot
    {
        Product::withoutTenancy()->whereKey($lot->product_id)->lockForUpdate()->first();

        return ProductLot::withoutTenancy()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
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
            'establishment_id' => $lot->establishment_id,
            'product_id' => $lot->product_id,
            'lot_id' => $lot->id,
            'type' => $type,
            'quantity' => $quantity,
            'lot_balance_after' => $lot->remaining_quantity,
            // Saldo del producto en el local del lote (spec 017).
            'product_balance_after' => Decimal::sum(ProductLot::withoutTenancy()
                ->where('product_id', $lot->product_id)->where('establishment_id', $lot->establishment_id)
                ->pluck('remaining_quantity')),
            'created_by' => $actor->id,
            ...$extra,
        ]);
    }

    private function normalize(string $quantity): string
    {
        return Decimal::fromDb($quantity, self::SCALE);
    }
}

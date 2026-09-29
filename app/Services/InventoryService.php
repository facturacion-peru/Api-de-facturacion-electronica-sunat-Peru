<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** Stock físico del producto (suma de saldos de sus lotes). */
    public function balance(Product $product): string
    {
        return $this->normalize((string) ProductLot::withoutTenancy()->where('product_id', $product->id)->sum('remaining_quantity'));
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

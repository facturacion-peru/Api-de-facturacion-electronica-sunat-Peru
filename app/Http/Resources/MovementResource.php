<?php

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** @mixin InventoryMovement */
class MovementResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'lot' => ['id' => $this->lot_id, 'lot_number' => $this->lot?->lot_number],
            'lot_balance_after' => $this->lot_balance_after,
            'product_balance_after' => $this->product_balance_after,
            'reason' => $this->reason?->value,
            'note' => $this->note,
            'source' => $this->source_type ? ['type' => Str::snake(class_basename($this->source_type)), 'id' => $this->source_id] : null,
            'reverses_id' => $this->reverses_id,
            'reversed' => $this->relationLoaded('reversal') ? $this->reversal !== null : $this->reversal()->exists(),
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

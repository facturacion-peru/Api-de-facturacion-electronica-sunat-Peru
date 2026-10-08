<?php

namespace App\Validation;

use App\Enums\UnitOfMeasure;
use App\Models\Product;
use App\Rules\QuantityForUnit;
use Illuminate\Validation\Rule;

/** Reglas de una entrada de mercadería (spec 002, HU-2), compartidas con la importación (spec 014). */
final class StockEntryValidation
{
    /**
     * Con `$product` null (producto que se crea en la importación), se usan la
     * unidad y el control de vencimiento que trae la fila.
     *
     * @return array<string, mixed>
     */
    public static function rules(?Product $product, ?UnitOfMeasure $unit = null, bool $tracksExpiry = false): array
    {
        $unit = $product?->unit ?? $unit;
        $tracksExpiry = $product?->tracks_expiry ?? $tracksExpiry;

        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3', new QuantityForUnit($unit)],
            'received_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'lot_number' => ['sometimes', 'nullable', 'string', 'max:40',
                ...($product ? [Rule::unique('product_lots', 'lot_number')->where('product_id', $product->id)] : [])],
            'expires_at' => $tracksExpiry
                ? ['required', 'date', 'after_or_equal:today']
                : ['prohibited'],
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'lot_number.unique' => 'Este producto ya tiene un lote con ese número.',
            'expires_at.required' => 'Este producto controla vencimiento: indica la fecha.',
            'expires_at.after_or_equal' => 'La fecha de vencimiento no puede ser pasada.',
            'expires_at.prohibited' => 'Este producto no controla vencimiento.',
        ];
    }
}

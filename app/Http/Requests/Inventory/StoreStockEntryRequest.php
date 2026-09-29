<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Models\Product;
use App\Rules\QuantityForUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Entrada de mercadería (HU-2): solo la cantidad es obligatoria (CE-003). */
class StoreStockEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->product();

        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3', new QuantityForUnit($product->unit)],
            'received_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'lot_number' => ['sometimes', 'nullable', 'string', 'max:40',
                Rule::unique('product_lots', 'lot_number')->where('product_id', $product->id)],
            'expires_at' => $product->tracks_expiry
                ? ['required', 'date', 'after_or_equal:today']
                : ['prohibited'],
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lot_number.unique' => 'Este producto ya tiene un lote con ese número.',
            'expires_at.required' => 'Este producto controla vencimiento: indica la fecha.',
            'expires_at.after_or_equal' => 'La fecha de vencimiento no puede ser pasada.',
            'expires_at.prohibited' => 'Este producto no controla vencimiento.',
        ];
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}

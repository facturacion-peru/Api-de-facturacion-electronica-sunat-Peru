<?php

namespace App\Http\Requests\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\CompanyRole;
use App\Models\ProductLot;
use App\Rules\QuantityForUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ajuste de un lote (HU-4.1): positivo o negativo, nunca cero. */
class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ProductLot $lot */
        $lot = $this->route('lot');

        return [
            'quantity' => ['required', 'numeric', 'not_in:0', 'decimal:0,3', 'between:-99999999999,99999999999',
                new QuantityForUnit($lot->product?->unit)],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['quantity.not_in' => 'El ajuste no puede ser cero.'];
    }
}

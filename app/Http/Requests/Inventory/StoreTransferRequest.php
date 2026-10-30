<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Models\Product;
use App\Rules\QuantityForUnit;
use Illuminate\Foundation\Http\FormRequest;

/** Traslado entre locales (spec 017, HU-3). Los locales los valida EstablishmentAccess. */
class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'from_establishment_id' => ['required', 'integer'],
            'to_establishment_id' => ['required', 'integer', 'different:from_establishment_id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3', new QuantityForUnit($product->unit)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to_establishment_id.different' => 'El destino debe ser otro local.',
            'quantity.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}

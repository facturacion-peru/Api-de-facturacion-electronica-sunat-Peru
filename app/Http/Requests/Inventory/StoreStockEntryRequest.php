<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Models\Product;
use App\Validation\StockEntryValidation;
use Illuminate\Foundation\Http\FormRequest;

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
        return StockEntryValidation::rules($this->product());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return StockEntryValidation::messages();
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}

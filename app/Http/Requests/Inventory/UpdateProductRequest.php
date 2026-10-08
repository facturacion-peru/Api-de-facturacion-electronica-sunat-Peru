<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Models\Product;
use App\Validation\ProductValidation;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ProductValidation::rules(['sometimes', 'required'], $this->all(), $this->product());

        return [...$rules, 'active' => ['sometimes', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProductValidation::messages();
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}

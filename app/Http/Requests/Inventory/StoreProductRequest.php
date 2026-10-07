<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Validation\ProductValidation;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ProductValidation::rules(['required'], $this->all());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProductValidation::messages();
    }
}

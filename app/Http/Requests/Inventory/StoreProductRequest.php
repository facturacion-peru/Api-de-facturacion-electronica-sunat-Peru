<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    use ProductRules;

    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->productRules(['required']);
    }

    protected function effectiveType(): ?ProductType
    {
        return ProductType::tryFrom((string) $this->input('type'));
    }
}

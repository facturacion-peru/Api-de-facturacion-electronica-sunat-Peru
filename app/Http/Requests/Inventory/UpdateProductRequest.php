<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    use ProductRules;

    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->productRules(['sometimes', 'required'], $this->product()->id),
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function effectiveType(): ?ProductType
    {
        return $this->has('type') ? ProductType::tryFrom((string) $this->input('type')) : $this->product()->type;
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}

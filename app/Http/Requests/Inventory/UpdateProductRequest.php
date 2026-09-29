<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\Product;
use Closure;
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
        $rules = $this->productRules(['sometimes', 'required'], $this->product()->id);
        $rules['type'][] = function (string $attribute, mixed $value, Closure $fail) {
            if ($value === ProductType::Service->value && bccomp($this->product()->stock(), '0', 3) > 0) {
                $fail('No se puede convertir en servicio un producto con stock.');
            }
        };

        return [...$rules, 'active' => ['sometimes', 'boolean']];
    }

    protected function effectiveType(): ?ProductType
    {
        return $this->has('type') ? ProductType::tryFrom((string) $this->input('type')) : $this->product()->type;
    }

    protected function effectiveUnit(): ?UnitOfMeasure
    {
        return $this->has('unit') ? UnitOfMeasure::tryFrom((string) $this->input('unit')) : $this->product()->unit;
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}

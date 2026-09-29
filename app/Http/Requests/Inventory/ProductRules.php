<?php

namespace App\Http\Requests\Inventory;

use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Rules\QuantityForUnit;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Validation\Rule;

/** Reglas compartidas por el alta y la edición de productos. */
trait ProductRules
{
    /**
     * @param  array<string>  $presence  'required' en el alta, 'sometimes' en la edición
     * @return array<string, mixed>
     */
    protected function productRules(array $presence, ?int $ignoreId = null): array
    {
        return [
            'code' => [...$presence, 'string', 'max:32',
                Rule::unique('products', 'code')->where('company_id', app(TenantContext::class)->id())->ignore($ignoreId)],
            'name' => [...$presence, 'string', 'max:255'],
            'type' => [...$presence, Rule::enum(ProductType::class)],
            'unit' => [...$presence, Rule::enum(UnitOfMeasure::class)],
            'sale_price' => [...$presence, 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'igv_affectation' => [...$presence, Rule::enum(\App\Enums\IgvAffectation::class)],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', new QuantityForUnit($this->effectiveUnit()),
                $this->onlyForGoods('El stock mínimo no aplica a servicios.')],
            'tracks_expiry' => ['boolean', $this->onlyForGoods('Un servicio no controla vencimiento.', requireTruthy: true)],
        ];
    }

    /** Tipo efectivo: el enviado o, en la edición, el actual. */
    abstract protected function effectiveType(): ?ProductType;

    /** Unidad efectiva: la enviada o, en la edición, la actual. */
    abstract protected function effectiveUnit(): ?UnitOfMeasure;

    private function onlyForGoods(string $message, bool $requireTruthy = false): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message, $requireTruthy) {
            $isSet = $requireTruthy ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value !== null;

            if ($isSet && $this->effectiveType() === ProductType::Service) {
                $fail($message);
            }
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.unique' => 'Ya existe un producto con este código.'];
    }
}

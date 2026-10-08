<?php

namespace App\Validation;

use App\Enums\IgvAffectation;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\Product;
use App\Rules\QuantityForUnit;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Reglas del alta y la edición de productos. Las usan los FormRequest y la
 * importación (spec 014), para que una fila importada se valide igual que una
 * alta manual.
 */
final class ProductValidation
{
    /**
     * @param  array<string>  $presence  'required' en el alta, 'sometimes' en la edición
     * @param  array<string, mixed>  $input  datos que se validan
     * @return array<string, mixed>
     */
    public static function rules(array $presence, array $input, ?Product $existing = null): array
    {
        $type = self::effectiveType($input, $existing);
        $unit = array_key_exists('unit', $input) ? UnitOfMeasure::tryFrom((string) $input['unit']) : $existing?->unit;

        $rules = [
            'code' => [...$presence, 'string', 'max:32',
                Rule::unique('products', 'code')->where('company_id', app(TenantContext::class)->id())->ignore($existing?->id)],
            'name' => [...$presence, 'string', 'max:255'],
            'type' => [...$presence, Rule::enum(ProductType::class)],
            'unit' => [...$presence, Rule::enum(UnitOfMeasure::class)],
            'sale_price' => [...$presence, 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'igv_affectation' => [...$presence, Rule::enum(IgvAffectation::class)],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', new QuantityForUnit($unit),
                self::onlyForGoods($type, 'El stock mínimo no aplica a servicios.')],
            'tracks_expiry' => ['boolean', self::onlyForGoods($type, 'Un servicio no controla vencimiento.', requireTruthy: true)],
        ];

        if ($existing !== null) {
            $rules['type'][] = function (string $attribute, mixed $value, Closure $fail) use ($existing) {
                if ($value === ProductType::Service->value && bccomp($existing->stock(), '0', 3) > 0) {
                    $fail('No se puede convertir en servicio un producto con stock.');
                }
            };
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['code.unique' => 'Ya existe un producto con este código.'];
    }

    /**
     * Tipo efectivo: el enviado o, en la edición, el actual.
     *
     * @param  array<string, mixed>  $input
     */
    public static function effectiveType(array $input, ?Product $existing = null): ?ProductType
    {
        return array_key_exists('type', $input) ? ProductType::tryFrom((string) $input['type']) : $existing?->type;
    }

    private static function onlyForGoods(?ProductType $type, string $message, bool $requireTruthy = false): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type, $message, $requireTruthy) {
            $isSet = $requireTruthy ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value !== null;

            if ($isSet && $type === ProductType::Service) {
                $fail($message);
            }
        };
    }
}

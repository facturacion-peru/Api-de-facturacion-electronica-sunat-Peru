<?php

namespace App\Http\Requests\Sales;

use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Rules\QuantityForUnit;
use App\Support\Decimal;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Venta con ticket (HU-1). El precio nunca viene del cliente (RF-011). */
class StoreTicketRequest extends FormRequest
{
    /** Venden el administrador y el vendedor (RF-012, A-30). */
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_document' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->where('company_id', app(TenantContext::class)->id())->where('active', true)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['lines.*.product_id.exists' => 'El producto no existe o está desactivado.'];
    }

    /** Reglas que dependen del producto: decimales según la unidad y descuento ≤ importe. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $products = Product::whereIn('id', collect($this->input('lines'))->pluck('product_id'))->get()->keyBy('id');

            foreach ($this->input('lines') as $i => $line) {
                $product = $products[$line['product_id']];

                (new QuantityForUnit($product->unit))->validate("lines.{$i}.quantity", $line['quantity'],
                    fn (string $message) => $validator->errors()->add("lines.{$i}.quantity", $message));

                $gross = Decimal::mul((string) $line['quantity'], $product->sale_price);

                if (bccomp(Decimal::round((string) ($line['discount'] ?? '0')), $gross, 2) > 0) {
                    $validator->errors()->add("lines.{$i}.discount", "El descuento no puede superar el importe de la línea ({$gross}).");
                }
            }
        }];
    }
}

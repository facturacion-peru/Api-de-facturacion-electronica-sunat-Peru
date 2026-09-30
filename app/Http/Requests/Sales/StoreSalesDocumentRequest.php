<?php

namespace App\Http\Requests\Sales;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Rules\QuantityForUnit;
use App\Support\Decimal;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Emitir boleta o factura desde «Vender» (spec 005, A-33): mismas líneas y
 * clave de idempotencia que el ticket. Las reglas tributarias (serie, RUC,
 * tope de S/ 700, Nuevo RUS) las aplica SalesDocumentService.
 */
class StoreSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = app(TenantContext::class)->id();

        return [
            'idempotency_key' => ['required', 'uuid'],
            // Las notas de crédito se emiten desde su comprobante (spec 007), no aquí.
            'document_type' => ['required', Rule::in([DocumentType::Invoice->value, DocumentType::Receipt->value])],
            'series_id' => ['sometimes', 'nullable', 'integer', Rule::exists('series', 'id')->where('company_id', $company)],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $company)],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $company)->where('active', true)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /** Cantidad válida para la unidad y descuento que no supera el importe (como en el ticket). */
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

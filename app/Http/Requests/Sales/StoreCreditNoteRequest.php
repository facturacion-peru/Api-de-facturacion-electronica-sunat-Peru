<?php

namespace App\Http\Requests\Sales;

use App\Enums\CreditNoteReason;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nota de crédito sobre un comprobante (spec 007). Cualquier usuario de la
 * empresa (A-41). Las cantidades frente a lo que queda las revisa el servicio.
 */
class StoreCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::enum(CreditNoteReason::class)],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
            'restock' => ['sometimes', 'boolean'],
            'series_id' => ['sometimes', 'nullable', 'integer', Rule::exists('series', 'id')->where('company_id', app(TenantContext::class)->id())],
            'lines' => ['required_if:reason_code,'.CreditNoteReason::ItemReturn->value, 'array', 'max:100'],
            'lines.*.line_position' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['lines.required_if' => 'Indica qué productos y cantidades se devuelven.'];
    }
}

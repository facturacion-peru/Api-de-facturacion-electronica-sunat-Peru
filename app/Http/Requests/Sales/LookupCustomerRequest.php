<?php

namespace App\Http\Requests\Sales;

use App\Enums\CustomerDocumentType;
use App\Validation\CustomerValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Buscar al cliente por DNI o RUC (spec 018, RF-005): el formato se valida antes de consultar. */
class LookupCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomerValidation::normalize($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(CustomerDocumentType::class)],
            'document_number' => ['required', 'string', 'max:15'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = CustomerDocumentType::tryFrom((string) $this->input('document_type'));
                if ($type !== null && ! in_array($type, [CustomerDocumentType::Dni, CustomerDocumentType::Ruc], true)) {
                    $validator->errors()->add('document_type', 'Este documento se registra a mano: solo se consultan DNI y RUC.');
                }
            },
            CustomerValidation::after($this->all()),
        ];
    }

    public function documentType(): CustomerDocumentType
    {
        return CustomerDocumentType::from((string) $this->validated('document_type'));
    }
}

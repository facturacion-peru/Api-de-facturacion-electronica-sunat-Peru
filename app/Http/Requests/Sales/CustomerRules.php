<?php

namespace App\Http\Requests\Sales;

use App\Enums\CustomerDocumentType;
use App\Rules\Ruc;
use App\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Reglas compartidas del alta y la edición de clientes (RF-030). */
trait CustomerRules
{
    protected function normalizeCustomer(): void
    {
        $merge = [];
        foreach (['document_number', 'name', 'address'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }
        if (isset($merge['document_number'])) {
            $merge['document_number'] = strtoupper($merge['document_number']);
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    protected function customerRules(bool $partial, ?int $ignoreId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'document_type' => [$partial ? 'required_with:document_number' : 'required', Rule::enum(CustomerDocumentType::class)],
            'document_number' => [$partial ? 'required_with:document_type' : 'required', 'string', 'max:15',
                Rule::unique('customers', 'document_number')
                    ->where('company_id', app(TenantContext::class)->id())
                    ->where('document_type', (string) $this->input('document_type'))
                    ->ignore($ignoreId)],
            'name' => [$required, 'string', 'max:200'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['document_number.unique' => 'Ya existe un cliente con ese documento.'];
    }

    /** Formato según el tipo; el RUC además con dígito verificador (regla de la 001). */
    public function after(): array
    {
        return [function (Validator $validator) {
            $type = CustomerDocumentType::tryFrom((string) $this->input('document_type'));
            $number = (string) $this->input('document_number');

            if (! $type || $validator->errors()->has('document_number')) {
                return;
            }

            if (preg_match($type->pattern(), $number) !== 1) {
                $validator->errors()->add('document_number', $type->formatHint());
            } elseif ($type === CustomerDocumentType::Ruc) {
                (new Ruc)->validate('document_number', $number, fn (string $message) => $validator->errors()->add('document_number', $message));
            }
        }];
    }
}

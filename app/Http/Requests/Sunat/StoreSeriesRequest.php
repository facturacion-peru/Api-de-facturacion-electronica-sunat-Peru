<?php

namespace App\Http\Requests\Sunat;

use App\Enums\CompanyRole;
use App\Enums\DocumentType;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'code' => ['required', 'string', 'size:4',
                Rule::unique('series', 'code')->where('company_id', app(TenantContext::class)->id())],
            'last_number' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.unique' => "Ya existe la serie {$this->code} en tu empresa."];
    }

    /** El formato depende del tipo: F### para factura, B### para boleta (RF-021). */
    public function after(): array
    {
        return [function (Validator $validator) {
            $type = DocumentType::tryFrom((string) $this->input('document_type'));

            if ($type && ! $validator->errors()->has('code') && ! preg_match($type->seriesPattern(), (string) $this->input('code'))) {
                $validator->errors()->add('code', "La serie de {$type->label()} debe empezar por {$type->seriesPrefix()} y tener 4 caracteres (p. ej. {$type->seriesPrefix()}001).");
            }
        }];
    }
}

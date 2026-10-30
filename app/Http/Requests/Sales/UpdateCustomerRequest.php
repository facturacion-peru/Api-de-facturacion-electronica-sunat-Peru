<?php

namespace App\Http\Requests\Sales;

use App\Enums\CompanyRole;
use App\Validation\CustomerValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Solo el administrador edita clientes (A-34). */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomerValidation::normalize($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CustomerValidation::rules(partial: true, input: $this->all(), ignoreId: $this->route('customer')?->id);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CustomerValidation::messages();
    }

    public function after(): array
    {
        return [
            CustomerValidation::after($this->all()),
            // Spec 018 (A-92): lo verificado solo cambia con «Actualizar datos».
            function (Validator $validator) {
                $customer = $this->route('customer');
                if (! $customer?->isVerified()) {
                    return;
                }

                foreach (['name', 'address'] as $field) {
                    if ($this->has($field) && ($this->input($field) ?: null) !== ($customer->{$field} ?: null)) {
                        $validator->errors()->add($field, 'Cliente verificado: sus datos vienen de SUNAT o RENIEC. Usa «Actualizar datos».');
                    }
                }
            },
        ];
    }
}

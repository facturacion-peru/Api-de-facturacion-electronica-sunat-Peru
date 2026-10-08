<?php

namespace App\Http\Requests\Sales;

use App\Enums\CompanyRole;
use App\Validation\CustomerValidation;
use Illuminate\Foundation\Http\FormRequest;

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
        return [CustomerValidation::after($this->all())];
    }
}

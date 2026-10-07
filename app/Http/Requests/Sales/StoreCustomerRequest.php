<?php

namespace App\Http\Requests\Sales;

use App\Validation\CustomerValidation;
use Illuminate\Foundation\Http\FormRequest;

/** Cualquier usuario de la empresa registra clientes al vender (A-34). */
class StoreCustomerRequest extends FormRequest
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
        return CustomerValidation::rules(partial: false, input: $this->all());
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

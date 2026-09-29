<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/** Cualquier usuario de la empresa registra clientes al vender (A-34). */
class StoreCustomerRequest extends FormRequest
{
    use CustomerRules;

    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeCustomer();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->customerRules(partial: false);
    }
}

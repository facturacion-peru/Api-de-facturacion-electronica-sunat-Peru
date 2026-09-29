<?php

namespace App\Http\Requests\Sales;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** Solo el administrador edita clientes (A-34). */
class UpdateCustomerRequest extends FormRequest
{
    use CustomerRules;

    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeCustomer();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->customerRules(partial: true, ignoreId: $this->route('customer')?->id);
    }
}

<?php

namespace App\Http\Requests\Establishments;

use App\Enums\CompanyRole;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** El código y el estado tienen reglas propias en EstablishmentService (A-89). */
class UpdateEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'regex:/^\d{4}$/',
                Rule::unique('establishments', 'code')->where('company_id', app(TenantContext::class)->id())->ignore($this->route('establishment'))],
            'name' => ['sometimes', 'string', 'max:100'],
            'address' => ['sometimes', 'string', 'max:200'],
            'ubigeo' => ['sometimes', 'string', 'size:6', Rule::exists('ubi_distritos', 'id')],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return (new StoreEstablishmentRequest)->messages() + [
            'code.unique' => "Ya tienes un local con el código {$this->code}.",
        ];
    }
}

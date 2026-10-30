<?php

namespace App\Http\Requests\Establishments;

use App\Enums\CompanyRole;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta de un local con el código de anexo de la ficha RUC (A-89). */
class StoreEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{4}$/',
                Rule::unique('establishments', 'code')->where('company_id', app(TenantContext::class)->id())],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:200'],
            'ubigeo' => ['required', 'string', 'size:6', Rule::exists('ubi_distritos', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'El código de anexo tiene 4 dígitos (p. ej. 0001), como figura en tu ficha RUC.',
            'code.unique' => "Ya tienes un local con el código {$this->code}.",
            'ubigeo.exists' => 'Elige un distrito de la lista.',
        ];
    }
}

<?php

namespace App\Http\Requests\Platform;

use App\Enums\TaxRegime;
use App\Rules\Ruc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Corrección de datos de una empresa, incluido el RUC (HU-5.2, HU-7). */
class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ruc' => ['sometimes', 'bail', 'required', new Ruc, Rule::unique('companies', 'ruc')->ignore($this->route('company'))],
            'razon_social' => ['sometimes', 'required', 'string', 'max:255'],
            'nombre_comercial' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_regime' => ['sometimes', 'required', Rule::enum(TaxRegime::class)],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Domicilio fiscal: dirección y ubigeo del establecimiento principal (spec 006, HU-3).
            'fiscal_address' => ['sometimes', 'array'],
            'fiscal_address.address' => ['required_with:fiscal_address', 'string', 'max:255'],
            'fiscal_address.ubigeo' => ['required_with:fiscal_address', 'string', Rule::exists('ubi_distritos', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['ruc.unique' => 'Esta empresa ya está registrada en la plataforma.'];
    }
}

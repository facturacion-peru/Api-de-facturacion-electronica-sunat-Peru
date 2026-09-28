<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** HU-5: el administrador edita solo nombre comercial, correo y teléfono. */
class UpdateCompanyRequest extends FormRequest
{
    /** Datos que solo corrige el administrador de la plataforma (HU-5.2). */
    private const PLATFORM_ONLY = ['ruc', 'razon_social', 'person_type', 'tax_regime', 'active'];

    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre_comercial' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            ...array_fill_keys(self::PLATFORM_ONLY, ['prohibited']),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_combine(
            array_map(fn (string $field) => "{$field}.prohibited", self::PLATFORM_ONLY),
            array_fill(0, count(self::PLATFORM_ONLY), 'Este dato solo lo puede corregir el administrador de la plataforma.'),
        );
    }
}

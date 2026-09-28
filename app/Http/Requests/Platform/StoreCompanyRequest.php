<?php

namespace App\Http\Requests\Platform;

use App\Enums\TaxRegime;
use App\Models\User;
use App\Rules\Ruc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ruc' => is_string($this->ruc) ? trim($this->ruc) : $this->ruc,
            'admin_email' => is_string($this->admin_email) ? User::normalizeEmail($this->admin_email) : $this->admin_email,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ruc' => ['bail', 'required', new Ruc, 'unique:companies,ruc'],
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'tax_regime' => ['required', Rule::enum(TaxRegime::class)],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'ubigeo' => ['required', 'string', 'size:6', 'exists:ubi_distritos,id'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ruc.unique' => 'Esta empresa ya está registrada en la plataforma.',
            'admin_email.unique' => 'Este correo ya tiene una cuenta.',
        ];
    }
}

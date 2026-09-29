<?php

namespace App\Http\Requests\Sunat;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSunatCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sol_user' => ['required', 'string', 'regex:/^[A-Za-z0-9]{3,20}$/'],
            'sol_password' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['sol_user.regex' => 'El usuario SOL solo admite letras y números (3 a 20).'];
    }
}

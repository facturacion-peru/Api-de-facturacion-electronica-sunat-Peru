<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use App\Models\Invitation;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => User::normalizeEmail($this->email)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => [
                'bail', 'required', 'email', 'max:255',
                // HU-3.4: no revela si el correo pertenece a otra empresa.
                Rule::unique('users', 'email'),
                function (string $attribute, mixed $value, Closure $fail) {
                    $pending = Invitation::query()
                        ->where('email', $value)
                        ->whereNull('accepted_at')
                        ->where('expires_at', '>', now())
                        ->exists();

                    if ($pending) {
                        $fail('Ya hay una invitación pendiente para este correo. Puedes reenviarla.');
                    }
                },
            ],
            'role' => ['required', Rule::enum(CompanyRole::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'Este correo no está disponible.',
        ];
    }
}

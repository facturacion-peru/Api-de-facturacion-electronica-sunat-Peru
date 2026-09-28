<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(CompanyRole::class)],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

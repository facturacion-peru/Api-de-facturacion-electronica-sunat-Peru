<?php

namespace App\Http\Requests\Sales;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** Solo el administrador anula tickets (A-18). */
class VoidTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }
}

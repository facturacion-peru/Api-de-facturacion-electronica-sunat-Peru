<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

class IndexMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            // Sin local: todos (spec 017).
            'establishment_id' => ['sometimes', 'integer'],
        ];
    }
}

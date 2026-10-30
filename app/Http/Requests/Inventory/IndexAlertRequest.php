<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

class IndexAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Sin local: todos (spec 017).
            'establishment_id' => ['sometimes', 'integer'],
        ];
    }
}

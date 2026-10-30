<?php

namespace App\Http\Requests\Establishments;

use App\Enums\CompanyRole;
use App\Models\Establishment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Locales de un vendedor (A-87): al menos uno activo de la empresa. */
class UpdateUserEstablishmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'establishment_ids' => ['required', 'array', 'min:1'],
            'establishment_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'establishment_ids.required' => 'Elige al menos un local.',
            'establishment_ids.min' => 'Elige al menos un local.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = array_map('intval', $this->input('establishment_ids'));
            // Con el scope de empresa, un local de otra no existe.
            $active = Establishment::active()->whereIn('id', $ids)->count();

            if ($active !== count($ids)) {
                $validator->errors()->add('establishment_ids', 'Elige locales activos de tu empresa.');
            }
        }];
    }
}

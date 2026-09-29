<?php

namespace App\Http\Requests\Sunat;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** Solo se activa o desactiva: el correlativo y el código no se editan (RF-022/023). */
class UpdateSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'last_number' => ['prohibited'],
            'code' => ['prohibited'],
            'document_type' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'last_number.prohibited' => 'El correlativo solo avanza con la emisión; no se puede editar.',
            'code.prohibited' => 'El código de la serie no se puede cambiar.',
            'document_type.prohibited' => 'El tipo de comprobante no se puede cambiar.',
        ];
    }
}

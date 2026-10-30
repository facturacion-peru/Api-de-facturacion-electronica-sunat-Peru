<?php

namespace App\Http\Requests\Establishments;

use Illuminate\Foundation\Http\FormRequest;

class IndexEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Solo el administrador ve los inactivos: su historial sigue visible (HU-4).
            'include_inactive' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/** Filtros del catálogo; como FormRequest quedan documentados en OpenAPI. */
class IndexProductRequest extends FormRequest
{
    /** Cualquier usuario de la empresa consulta el catálogo (HU-1). */
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'in:good,service'],
            'status' => ['sometimes', 'in:active,inactive,all'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

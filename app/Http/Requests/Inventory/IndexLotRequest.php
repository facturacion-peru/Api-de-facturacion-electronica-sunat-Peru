<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class IndexLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'include_empty' => ['sometimes', 'boolean'],
            // Sin local: los que el usuario puede leer (spec 017).
            'establishment_id' => ['sometimes', 'integer'],
        ];
    }
}

<?php

namespace App\Http\Requests\Sunat;

use Illuminate\Foundation\Http\FormRequest;

class IndexSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Sin local: los que el usuario puede leer (spec 017).
            'establishment_id' => ['sometimes', 'integer'],
        ];
    }
}

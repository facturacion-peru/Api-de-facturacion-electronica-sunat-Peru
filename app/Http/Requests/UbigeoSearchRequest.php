<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UbigeoSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => 'required|string|min:3|max:255',
            'region_id' => 'nullable|string|exists:ubi_regiones,id',
            'provincia_id' => 'nullable|string|exists:ubi_provincias,id',
        ];
    }
    
    public function messages(): array
    {
        return [
            'search.required' => 'El campo de búsqueda es requerido',
            'search.min' => 'La búsqueda debe tener al menos 3 caracteres',
            'search.max' => 'La búsqueda no puede exceder 255 caracteres',
            'region_id.exists' => 'La región seleccionada no es válida',
            'provincia_id.exists' => 'La provincia seleccionada no es válida',
        ];
    }
}

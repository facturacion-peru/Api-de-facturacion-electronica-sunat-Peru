<?php

namespace App\Http\Requests\DataTransfer;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** Primer paso de una importación (spec 014): archivo XLSX o CSV de hasta 5 MB (A-73). */
class PreviewImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
            'mode' => ['sometimes', 'in:create,upsert'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => 'El archivo no puede pesar más de 5 MB.',
            'file.mimes' => 'El archivo debe ser un XLSX o un CSV.',
        ];
    }

    public function mode(): string
    {
        return (string) $this->input('mode', 'create');
    }
}

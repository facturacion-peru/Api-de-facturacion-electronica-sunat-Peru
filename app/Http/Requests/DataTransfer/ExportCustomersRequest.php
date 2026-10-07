<?php

namespace App\Http\Requests\DataTransfer;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** Exportación de clientes (spec 014, HU-2): la búsqueda es la del listado. */
class ExportCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'in:xlsx,csv'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function fileFormat(): string
    {
        return (string) $this->input('format', 'xlsx');
    }
}

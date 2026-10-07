<?php

namespace App\Http\Requests\DataTransfer;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** Exportación del catálogo (spec 014, HU-1): los filtros son los del listado. */
class ExportProductsRequest extends FormRequest
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
            'lots' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'in:good,service'],
            'status' => ['sometimes', 'in:active,inactive,all'],
        ];
    }

    /** @return array{status: string, type: ?string, search: ?string, lots: bool} */
    public function filters(): array
    {
        return [
            'status' => (string) $this->input('status', 'active'),
            'type' => $this->input('type'),
            'search' => $this->input('search'),
            'lots' => $this->boolean('lots'),
        ];
    }

    public function fileFormat(): string
    {
        return (string) $this->input('format', 'xlsx');
    }
}

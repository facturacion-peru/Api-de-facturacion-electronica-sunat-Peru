<?php

namespace App\Http\Requests\DataTransfer;

use App\DataTransfer\Exports\SalesExport;
use App\Enums\CompanyRole;
use App\Enums\SalesDocumentStatus;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Exportación de ventas (spec 014, HU-3): rango de fechas de Lima de hasta 12 meses (A-73). */
class ExportSalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $statuses = [...array_column(TicketStatus::cases(), 'value'), ...array_column(SalesDocumentStatus::cases(), 'value')];

        return [
            'format' => ['sometimes', 'in:xlsx,csv'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'types' => ['sometimes', 'array'],
            'types.*' => [Rule::in(array_keys(SalesExport::TYPES))],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => [Rule::in(array_unique($statuses))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            if (Carbon::parse($this->input('to'))->gte(Carbon::parse($this->input('from'))->addYear())) {
                $validator->errors()->add('to', 'El rango no puede superar los 12 meses.');
            }
        }];
    }

    /** @return array{from: string, to: string, types: list<string>, statuses: list<string>} */
    public function filters(): array
    {
        return [
            'from' => (string) $this->input('from'),
            'to' => (string) $this->input('to'),
            'types' => array_values((array) $this->input('types', [])),
            'statuses' => array_values((array) $this->input('statuses', [])),
        ];
    }

    public function fileFormat(): string
    {
        return (string) $this->input('format', 'xlsx');
    }
}

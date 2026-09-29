<?php

namespace App\Http\Requests\Sales;

use App\Enums\DocumentType;
use App\Enums\SalesDocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del listado de comprobantes (HU-6). */
class IndexSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'document_type' => ['sometimes', Rule::enum(DocumentType::class)],
            'status' => ['sometimes', Rule::enum(SalesDocumentStatus::class)],
            'customer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

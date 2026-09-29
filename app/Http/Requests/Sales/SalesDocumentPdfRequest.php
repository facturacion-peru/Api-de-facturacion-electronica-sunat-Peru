<?php

namespace App\Http\Requests\Sales;

use App\Sales\DocumentPdf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Formato del PDF del comprobante (RF-007). */
class SalesDocumentPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['format' => ['sometimes', Rule::in(DocumentPdf::FORMATS)]];
    }
}

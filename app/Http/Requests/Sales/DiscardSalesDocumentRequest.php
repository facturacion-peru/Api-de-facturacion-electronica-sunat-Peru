<?php

namespace App\Http\Requests\Sales;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

/** Descartar un comprobante rechazado: solo el administrador, con motivo (spec 007, A-42). */
class DiscardSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:250']];
    }
}

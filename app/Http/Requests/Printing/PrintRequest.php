<?php

namespace App\Http\Requests\Printing;

use App\Printing\PrintOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ajustes de la impresora térmica para las rutas de impresión (spec 015). */
class PrintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'width' => ['sometimes', 'integer', Rule::in(PrintOptions::WIDTHS)],
            'qr' => ['sometimes', Rule::in(PrintOptions::QR_MODES)],
        ];
    }

    public function options(): PrintOptions
    {
        return new PrintOptions((int) $this->validated('width', 80), $this->validated('qr', 'native'));
    }
}

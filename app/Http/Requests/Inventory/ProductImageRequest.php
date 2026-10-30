<?php

namespace App\Http\Requests\Inventory;

use App\Enums\CompanyRole;
use App\Images\Gd;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto del producto (spec 016, A-80): JPEG, PNG o WebP de hasta 2 MB y
 * 4000×4000. La app la reduce antes de subirla (A-85). El contenido real se
 * comprueba otra vez al procesarla.
 */
class ProductImageRequest extends FormRequest
{
    public const MAX_KILOBYTES = 2048;

    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.self::MAX_KILOBYTES,
                'dimensions:max_width='.Gd::MAX_SIDE.',max_height='.Gd::MAX_SIDE,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.image' => 'La foto debe ser JPEG, PNG o WebP.',
            'image.mimes' => 'La foto debe ser JPEG, PNG o WebP.',
            'image.max' => 'La foto no puede pesar más de 2 MB.',
            'image.dimensions' => 'La foto no puede medir más de '.Gd::MAX_SIDE.' × '.Gd::MAX_SIDE.' píxeles.',
        ];
    }
}

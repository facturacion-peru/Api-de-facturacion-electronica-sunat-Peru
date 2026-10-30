<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use App\Images\Gd;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // PNG o JPEG de hasta 1 MB (A-80) y 4000×4000: se vuelve a codificar como PNG con alfa (spec 016).
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width='.Gd::MAX_SIDE.',max_height='.Gd::MAX_SIDE],
        ];
    }
}

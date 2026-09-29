<?php

namespace App\Http\Requests\Sunat;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;

class UploadCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasCompanyRole(CompanyRole::CompanyAdmin);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'certificate' => ['required', 'file', 'max:1024', 'extensions:pfx,p12,pem'],
            'password' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['certificate.extensions' => 'Sube el certificado en formato .pfx, .p12 o .pem.'];
    }
}

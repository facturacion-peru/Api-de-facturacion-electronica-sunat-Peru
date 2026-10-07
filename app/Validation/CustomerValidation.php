<?php

namespace App\Validation;

use App\Enums\CustomerDocumentType;
use App\Rules\Ruc;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas del alta y la edición de clientes (RF-030 de la 005). Las usan los
 * FormRequest y la importación (spec 014).
 */
final class CustomerValidation
{
    /**
     * Recorta espacios y pasa el documento a mayúsculas. Solo devuelve los
     * campos presentes y de texto.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $normalized = [];
        foreach (['document_number', 'name', 'address'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $normalized[$field] = trim($input[$field]);
            }
        }
        if (isset($normalized['document_number'])) {
            $normalized['document_number'] = strtoupper($normalized['document_number']);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rules(bool $partial, array $input, ?int $ignoreId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'document_type' => [$partial ? 'required_with:document_number' : 'required', Rule::enum(CustomerDocumentType::class)],
            'document_number' => [$partial ? 'required_with:document_type' : 'required', 'string', 'max:15',
                Rule::unique('customers', 'document_number')
                    ->where('company_id', app(TenantContext::class)->id())
                    ->where('document_type', (string) ($input['document_type'] ?? ''))
                    ->ignore($ignoreId)],
            'name' => [$required, 'string', 'max:200'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['document_number.unique' => 'Ya existe un cliente con ese documento.'];
    }

    /**
     * Formato según el tipo; el RUC además con dígito verificador (regla de la 001).
     *
     * @param  array<string, mixed>  $input
     */
    public static function after(array $input): Closure
    {
        return function (Validator $validator) use ($input) {
            $type = CustomerDocumentType::tryFrom((string) ($input['document_type'] ?? ''));
            $number = (string) ($input['document_number'] ?? '');

            if (! $type || $validator->errors()->has('document_number')) {
                return;
            }

            if (preg_match($type->pattern(), $number) !== 1) {
                $validator->errors()->add('document_number', $type->formatHint());
            } elseif ($type === CustomerDocumentType::Ruc) {
                (new Ruc)->validate('document_number', $number, fn (string $message) => $validator->errors()->add('document_number', $message));
            }
        };
    }
}

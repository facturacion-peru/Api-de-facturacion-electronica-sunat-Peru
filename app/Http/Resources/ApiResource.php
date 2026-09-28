<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base de los recursos de la API: sobre { success: true, data }.
 * Cada subclase tiene su prueba de estructura en tests/Feature/Contract.
 */
abstract class ApiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return ['success' => true];
    }
}

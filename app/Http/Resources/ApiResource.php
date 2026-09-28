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
    /** Listado con el mismo sobre, paginado o no. */
    public static function collection($resource): ApiCollection
    {
        return new ApiCollection($resource, static::class);
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return ['success' => true];
    }
}

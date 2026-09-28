<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Listados con el sobre de la API (ver frontend src/core/api/types.ts):
 * { success: true, data: [...], meta } donde meta es solo paginación
 * (current_page, per_page, total, last_page, from, to) o { total }.
 */
class ApiCollection extends AnonymousResourceCollection
{
    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return $this->resource instanceof \Illuminate\Pagination\AbstractPaginator
            ? ['success' => true]
            : ['success' => true, 'meta' => ['total' => $this->collection->count()]];
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
                'last_page' => $paginated['last_page'],
                'from' => $paginated['from'],
                'to' => $paginated['to'],
            ],
        ];
    }
}

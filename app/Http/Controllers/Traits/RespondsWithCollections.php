<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Da una sola forma a las respuestas que devuelven colecciones.
 *
 * El contrato es siempre el mismo:
 *
 *   - `data` es un array plano de recursos, nunca un objeto paginador.
 *   - `meta` describe únicamente la paginación.
 *   - cualquier dato adicional del listado (contadores, contexto) viaja en
 *     claves propias de primer nivel, declaradas explícitamente por el
 *     controlador, para que `meta` no signifique dos cosas distintas.
 *
 * Así un cliente tipado puede leer cualquier listado con el mismo código.
 */
trait RespondsWithCollections
{
    /**
     * Respuesta para un listado paginado.
     *
     * @param  array<string, mixed>  $extra  Claves adicionales de primer nivel.
     */
    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        ?string $message = null,
        array $extra = []
    ): JsonResponse {
        return response()->json($this->collectionPayload(
            $paginator->items(),
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            $message,
            $extra
        ));
    }

    /**
     * Respuesta para un listado que no se pagina.
     *
     * Mantiene `meta.total` para que el consumidor no tenga que distinguir
     * entre listados paginados y completos cuando solo necesita el conteo.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $items
     * @param  array<string, mixed>  $extra  Claves adicionales de primer nivel.
     */
    protected function collectionResponse(
        Collection|array $items,
        ?string $message = null,
        array $extra = []
    ): JsonResponse {
        $items = $items instanceof Collection ? $items : collect($items);

        return response()->json($this->collectionPayload(
            $items->values()->all(),
            ['total' => $items->count()],
            $message,
            $extra
        ));
    }

    /**
     * @param  array<int, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function collectionPayload(array $data, array $meta, ?string $message, array $extra): array
    {
        $payload = [
            'success' => true,
            'data' => $data,
            'meta' => $meta,
        ];

        // `data` y `meta` van primero y siempre; el resto solo si aporta algo.
        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== []) {
                $payload[$key] = $value;
            }
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return $payload;
    }
}

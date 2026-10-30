<?php

namespace App\Http\Resources;

use App\Models\Establishment;

/** Referencia corta a un local en las filas de listas (spec 017). */
final class EstablishmentRef
{
    /** @return array{id: int, code: string, name: string}|null */
    public static function of(?Establishment $establishment): ?array
    {
        return $establishment ? ['id' => $establishment->id, 'code' => $establishment->code, 'name' => $establishment->name] : null;
    }

    /** Con la dirección, para el detalle y lo impreso. @return array{id: int, code: string, name: string, address: string}|null */
    public static function withAddress(?Establishment $establishment): ?array
    {
        return $establishment ? [...self::of($establishment), 'address' => $establishment->address] : null;
    }
}

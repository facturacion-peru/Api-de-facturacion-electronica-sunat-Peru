<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Estado de la emisión SUNAT para cualquier usuario de la empresa (HU-4):
 * sin secretos ni datos del certificado más allá de sus días de vigencia.
 */
class SunatStatusResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}

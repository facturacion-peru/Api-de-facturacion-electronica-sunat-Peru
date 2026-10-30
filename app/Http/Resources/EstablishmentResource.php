<?php

namespace App\Http\Resources;

use App\Models\Establishment;
use Illuminate\Http\Request;

/** @mixin Establishment */
class EstablishmentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'ubigeo' => $this->ubigeo,
            'district' => $this->district?->info_busqueda,
            'is_main' => $this->is_main,
            'active' => $this->active,
            // Con comprobantes, el código ya salió en XML firmados (A-89).
            'code_locked' => (bool) ($this->code_locked ?? $this->salesDocuments()->exists()),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Series;
use Illuminate\Http\Request;

/** @mixin Series */
class SeriesResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'code' => $this->code,
            'last_number' => $this->last_number,
            'next_number' => $this->nextNumberPreview(),
            'active' => $this->active,
            'establishment_code' => $this->establishment?->code,
        ];
    }
}

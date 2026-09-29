<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;

/** @mixin Customer */
class CustomerResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'document_number' => $this->document_number,
            'name' => $this->name,
            'address' => $this->address,
        ];
    }
}

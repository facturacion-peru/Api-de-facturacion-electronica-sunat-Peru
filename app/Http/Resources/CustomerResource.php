<?php

namespace App\Http\Resources;

use App\Enums\CustomerDocumentType;
use App\Models\Customer;
use App\Models\DocumentLookup;
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
            // Spec 018: traído de apisperu (A-92) y, si es RUC, su estado (A-95).
            'verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            ...$this->rucState(),
        ];
    }

    /** @return array{ruc_status: ?string, ruc_condition: ?string} */
    private function rucState(): array
    {
        $known = $this->isVerified() && $this->document_type === CustomerDocumentType::Ruc
            ? DocumentLookup::query()->where('document_type', $this->document_type)->where('document_number', $this->document_number)->first()
            : null;

        return ['ruc_status' => $known?->ruc_status, 'ruc_condition' => $known?->ruc_condition];
    }
}

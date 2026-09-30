<?php

namespace App\Http\Resources;

use App\Models\SalesDocument;
use Illuminate\Http\Request;

/**
 * Comprobante en la vista de soporte de la plataforma (spec 006, A-37):
 * solo los datos de envío. Nunca líneas, cliente ni importes.
 *
 * @mixin SalesDocument
 */
class PlatformSalesDocumentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company' => ['id' => $this->company->id, 'ruc' => $this->company->ruc, 'razon_social' => $this->company->razon_social],
            'display_number' => $this->display_number,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sunat_code' => $this->sunat_code,
            'sunat_message' => $this->sunat_message,
            'attempts' => $this->attempts,
            'issued_at' => $this->issued_at->toIso8601String(),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'can_retry' => ! $this->status->isFinal(),
        ];
    }
}

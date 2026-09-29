<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;

/**
 * Ticket interno. `legal_notice` viaja siempre, para que toda vista o
 * impresión muestre que no es comprobante de pago (RF-004, CE-002).
 *
 * @mixin Ticket
 */
class TicketResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'display_number' => $this->display_number,
            'status' => $this->status->value,
            'legal_notice' => Ticket::LEGAL_NOTICE,
            'seller' => $this->seller ? ['id' => $this->seller->id, 'name' => $this->seller->name] : null,
            'customer_name' => $this->customer_name,
            'customer_label' => $this->customer_name ?? 'Cliente varios',
            'customer_document' => $this->customer_document,
            'payment_method' => $this->payment_method->value,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'total' => $this->total,
            'issued_at' => $this->issued_at->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'product_code' => $line->product_code,
                'product_name' => $line->product_name,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'gross_amount' => $line->gross_amount,
                'discount' => $line->discount,
                'amount' => $line->amount,
            ])->all()),
        ];
    }
}

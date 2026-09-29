<?php

use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Tenancy\TenantContext;

/*
 * T061 · Contrato de TicketResource (principio III).
 */

$keys = ['id', 'number', 'display_number', 'status', 'legal_notice', 'seller', 'customer_name', 'customer_label',
    'customer_document', 'payment_method', 'subtotal', 'discount_total', 'total', 'issued_at', 'voided_at', 'void_reason'];

it('sin líneas cargadas expone exactamente los campos del encabezado', function () use ($keys) {
    $ticket = Ticket::factory()->create()->load('seller');

    expect(array_keys(TicketResource::make($ticket)->resolve()))->toBe($keys);
});

it('con líneas añade las líneas con sus campos', function () use ($keys) {
    $ticket = Ticket::factory()->create();
    app(TenantContext::class)->set($ticket->company);
    $ticket->load(['seller', 'lines']);

    expect(array_keys(TicketResource::make($ticket)->resolve()))->toBe([...$keys, 'lines']);
});

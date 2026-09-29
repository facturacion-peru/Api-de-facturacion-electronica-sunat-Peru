<?php

use App\Models\Ticket;

/*
 * T014 · Número visible del ticket (RF-002, A-18).
 */

it('muestra el número con el prefijo T y seis dígitos', function () {
    $ticket = Ticket::factory()->make(['number' => 42]);

    expect($ticket->display_number)->toBe('T-000042')
        ->and(Ticket::LEGAL_NOTICE)->toBe('Documento interno — no es comprobante de pago');
});

it('se crea por factory con BelongsToCompany', function () {
    $ticket = Ticket::factory()->create();

    expect(Ticket::withoutTenancy()->find($ticket->id)->company_id)->toBe($ticket->company_id);
});

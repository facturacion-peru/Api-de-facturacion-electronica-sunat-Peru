<?php

use App\Http\Resources\EstablishmentResource;
use App\Http\Resources\TicketResource;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Ticket;
use App\Tenancy\TenantContext;

/*
 * Spec 017 · T025: contrato de los locales (principio III). El frontend tipa
 * estas formas a mano en shared/establishments/types.ts.
 */

it('EstablishmentResource expone exactamente los campos declarados', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    app(TenantContext::class)->set($company);
    $establishment = Establishment::where('is_main', true)->sole()->load('district');

    expect(array_keys(EstablishmentResource::make($establishment)->resolve()))
        ->toBe(['id', 'code', 'name', 'address', 'ubigeo', 'district', 'is_main', 'active', 'code_locked']);
});

it('las filas llevan el local corto y el detalle, con dirección', function () {
    $ticket = Ticket::factory()->create();
    app(TenantContext::class)->set($ticket->company);

    $row = TicketResource::make($ticket->load('establishment'))->resolve()['establishment'];
    $detail = TicketResource::make($ticket->load(['establishment', 'lines', 'seller']))->resolve()['establishment'];

    expect(array_keys($row))->toBe(['id', 'code', 'name'])
        ->and(array_keys($detail))->toBe(['id', 'code', 'name', 'address']);
});

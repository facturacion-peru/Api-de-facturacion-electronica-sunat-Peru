<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * T030 · HU-2 y RF-004/RF-005: el ticket siempre dice que no es comprobante,
 * usa numeración propia y nunca se presenta como boleta ni factura.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->service = Product::factory()->service()->create(['company_id' => $this->company->id, 'name' => 'Delivery']);
});

function showTicket(User $as, int $id)
{
    app('auth')->forgetGuards();

    return test()->withToken($as->createToken('t')->plainTextToken)->getJson("/api/v1/tickets/{$id}");
}

it('muestra número propio, líneas y la leyenda de documento interno', function () {
    $id = $this->withToken($this->admin->createToken('t')->plainTextToken)->postJson('/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'card',
        'lines' => [['product_id' => $this->service->id, 'quantity' => '1']],
    ])->json('data.id');

    $response = showTicket($this->admin, $id)->assertOk()
        ->assertJsonPath('data.display_number', 'T-000001')
        ->assertJsonPath('data.legal_notice', 'Documento interno — no es comprobante de pago')
        ->assertJsonPath('data.lines.0.product_name', 'Delivery');

    expect(mb_strtolower($response->getContent()))->not->toContain('boleta')->not->toContain('factura');
});

it('un ticket anulado lo indica y conserva la leyenda', function () {
    $ticket = Ticket::factory()->voided()->create(['company_id' => $this->company->id]);

    showTicket($this->admin, $ticket->id)->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.void_reason', 'Error')
        ->assertJsonPath('data.legal_notice', 'Documento interno — no es comprobante de pago');
});

<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Sales\TicketPdf;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/*
 * Spec 013 · T004: PDF de 80 mm del ticket, para imprimir o compartir desde
 * la app Android (A-63). Mismo contenido que el ticket en pantalla y siempre
 * la leyenda de documento interno (spec 003, A-17).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create(['razon_social' => 'Bodega Demo S.A.C.', 'nombre_comercial' => 'Bodega Demo']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create(['name' => 'Luis Vendedor']);
    $this->other = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $service = Product::factory()->service()->create(['company_id' => $this->company->id, 'name' => 'Delivery', 'sale_price' => '5.00']);

    $this->ticketId = $this->withToken($this->seller->createToken('t')->plainTextToken)->postJson('/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'yape_plin', 'customer_name' => 'María',
        'lines' => [['product_id' => $service->id, 'quantity' => '2', 'discount' => '1.00']],
    ])->assertCreated()->json('data.id');

    $this->pdf = function (User $as, ?int $id = null) {
        app('auth')->forgetGuards();

        return $this->withToken($as->createToken('t')->plainTextToken)->get('/api/v1/tickets/'.($id ?? $this->ticketId).'/pdf');
    };
});

it('el PDF muestra el ticket, sus líneas, el total y la leyenda de documento interno', function () {
    app(TenantContext::class)->set($this->company);
    $html = app(TicketPdf::class)->html(Ticket::with(['lines', 'seller', 'company'])->findOrFail($this->ticketId));

    expect($html)->toContain('Bodega Demo')
        ->toContain('RUC '.$this->company->ruc)
        ->toContain('TICKET T-')
        ->toContain('Delivery')
        ->toContain('9.00')
        ->toContain('Yape / Plin')
        ->toContain('María')
        ->toContain('Luis Vendedor')
        ->toContain(Ticket::LEGAL_NOTICE);
    expect(mb_strtolower($html))->not->toContain('boleta')->not->toContain('factura');
});

it('un ticket anulado lo indica en el PDF', function () {
    app(TenantContext::class)->set($this->company);
    $ticket = Ticket::factory()->voided()->create(['company_id' => $this->company->id, 'number' => 99]);

    expect(app(TicketPdf::class)->html($ticket->load(['lines', 'seller', 'company'])))->toContain('ANULADO');
});

it('descarga el PDF de 80 mm del ticket', function () {
    $response = ($this->pdf)($this->seller);

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toMatch('/T-\d{6}-80mm\.pdf/')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('el administrador descarga cualquier ticket de la empresa', function () {
    ($this->pdf)($this->admin)->assertOk();
});

it('el vendedor no descarga tickets ajenos (A-30): 404 como inexistente', function () {
    ($this->pdf)($this->other)->assertNotFound();
});

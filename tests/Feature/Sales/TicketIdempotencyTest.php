<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/*
 * T023 · RF-008: confirmar la misma venta dos veces (reintento) no crea dos
 * tickets ni descuenta dos veces.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($this->product)->quantity('10')->create();
    $this->payload = [
        'idempotency_key' => (string) Str::uuid(),
        'payment_method' => 'cash',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '4']],
    ];
    $this->post = function (User $as, array $payload) {
        app('auth')->forgetGuards();

        return $this->withToken($as->createToken('t')->plainTextToken)->postJson('/api/v1/tickets', $payload);
    };
});

it('la misma clave devuelve el mismo ticket sin descontar de nuevo', function () {
    $first = ($this->post)($this->seller, $this->payload)->assertCreated();
    $second = ($this->post)($this->seller, $this->payload)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Ticket::withoutTenancy()->count())->toBe(1)
        ->and(ProductLot::withoutTenancy()->where('product_id', $this->product->id)->value('remaining_quantity'))->toBe('6.000');
});

it('la misma clave en otra empresa no colisiona', function () {
    ($this->post)($this->seller, $this->payload)->assertCreated();

    app(TenantContext::class)->clear();
    $otra = Company::factory()->withMainEstablishment()->create();
    $vendedor = User::factory()->forCompany($otra)->create();
    $servicio = Product::factory()->service()->create(['company_id' => $otra->id]);

    ($this->post)($vendedor, [...$this->payload, 'lines' => [['product_id' => $servicio->id, 'quantity' => '1']]])
        ->assertCreated()
        ->assertJsonPath('data.display_number', 'T-000001');

    expect(Ticket::withoutTenancy()->count())->toBe(2);
});

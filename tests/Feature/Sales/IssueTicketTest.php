<?php

use App\Enums\CompanyRole;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/*
 * T020 · HU-1 Registrar una venta con ticket (RF-001, RF-007, RF-009 a RF-013).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create(['name' => 'Luis']);

    $this->galletas = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'GAL-1', 'name' => 'Galletas', 'sale_price' => '2.99']);
    ProductLot::factory()->for($this->galletas)->quantity('10')->create();
    $this->azucar = Product::factory()->byWeight()->create(['company_id' => $this->company->id, 'code' => 'AZU-1', 'name' => 'Azúcar', 'sale_price' => '4.20']);
    ProductLot::factory()->for($this->azucar)->quantity('5')->create();
    $this->delivery = Product::factory()->service()->create(['company_id' => $this->company->id, 'name' => 'Delivery', 'sale_price' => '5.00']);

    $this->sell = function (array $data, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)
            ->postJson('/api/v1/tickets', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', ...$data]);
    };
});

it('HU-1.1 crea el ticket con número, líneas, totales, vendedor y descuenta el stock', function () {
    $response = ($this->sell)(['payment_method' => 'yape_plin', 'lines' => [
        ['product_id' => $this->galletas->id, 'quantity' => '3', 'discount' => '0.97'],
        ['product_id' => $this->azucar->id, 'quantity' => '0.375'],
    ]])->assertCreated();

    $response->assertJsonPath('data.display_number', 'T-000001')
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.payment_method', 'yape_plin')
        ->assertJsonPath('data.seller.name', 'Luis')
        ->assertJsonPath('data.subtotal', '10.55')      // 8.97 + 1.58 (0.375 × 4.20 = 1.575 → 1.58)
        ->assertJsonPath('data.discount_total', '0.97')
        ->assertJsonPath('data.total', '9.58')
        ->assertJsonPath('data.lines.0.gross_amount', '8.97')
        ->assertJsonPath('data.lines.0.amount', '8.00')
        ->assertJsonPath('data.lines.1.amount', '1.58');

    $ticket = Ticket::withoutTenancy()->sole();
    $sales = InventoryMovement::withoutTenancy()->where('type', MovementType::Sale)->get();

    expect($ticket->issued_at)->not->toBeNull()
        ->and($sales)->toHaveCount(2)
        ->and($sales->every(fn ($m) => $m->source_type === $ticket->getMorphClass() && $m->source_id === $ticket->id))->toBeTrue()
        ->and($this->galletas->fresh()->stock())->toBe('7.000')
        ->and(AuditLog::withoutTenancy()->where('action', 'ticket.issued')->count())->toBe(1);
});

it('numera de forma consecutiva por empresa', function () {
    ($this->sell)(['lines' => [['product_id' => $this->galletas->id, 'quantity' => '1']]])->assertJsonPath('data.display_number', 'T-000001');
    ($this->sell)(['lines' => [['product_id' => $this->galletas->id, 'quantity' => '1']]])->assertJsonPath('data.display_number', 'T-000002');

    app(TenantContext::class)->clear(); // la prueba crea datos de otra empresa entre peticiones
    $otra = Company::factory()->withMainEstablishment()->create();
    $vendedorOtra = User::factory()->forCompany($otra)->create();
    $producto = Product::factory()->service()->create(['company_id' => $otra->id]);

    ($this->sell)(['lines' => [['product_id' => $producto->id, 'quantity' => '1']]], $vendedorOtra)->assertJsonPath('data.display_number', 'T-000001');
});

it('HU-1.2 sin cliente el ticket sale a nombre de Cliente varios', function () {
    ($this->sell)(['lines' => [['product_id' => $this->delivery->id, 'quantity' => '1']]])
        ->assertCreated()
        ->assertJsonPath('data.customer_name', null)
        ->assertJsonPath('data.customer_label', 'Cliente varios');
});

it('guarda el cliente cuando se indica', function () {
    ($this->sell)(['customer_name' => 'María Quispe', 'customer_document' => '45678912', 'lines' => [['product_id' => $this->delivery->id, 'quantity' => '1']]])
        ->assertJsonPath('data.customer_label', 'María Quispe')
        ->assertJsonPath('data.customer_document', '45678912');
});

it('HU-1.4 un servicio se vende sin tocar el stock', function () {
    ($this->sell)(['lines' => [['product_id' => $this->delivery->id, 'quantity' => '2']]])
        ->assertCreated()->assertJsonPath('data.total', '10.00');

    expect(InventoryMovement::withoutTenancy()->where('type', MovementType::Sale)->count())->toBe(0);
});

it('HU-1.5 rechaza un descuento mayor que el importe de la línea', function () {
    $response = ($this->sell)(['lines' => [['product_id' => $this->galletas->id, 'quantity' => '1', 'discount' => '3.00']]])
        ->assertUnprocessable();

    expect($response->json('errors')['lines.0.discount'][0])->toBe('El descuento no puede superar el importe de la línea (2.99).');

    expect(Ticket::withoutTenancy()->count())->toBe(0);
});

it('RF-011 el precio sale del catálogo aunque el cliente envíe otro', function () {
    ($this->sell)(['lines' => [['product_id' => $this->galletas->id, 'quantity' => '1', 'unit_price' => '0.01']]])
        ->assertJsonPath('data.lines.0.unit_price', '2.99');
});

it('RF-009 el ticket conserva los datos del producto al vender', function () {
    $id = ($this->sell)(['lines' => [['product_id' => $this->galletas->id, 'quantity' => '1']]])->json('data.id');
    $this->galletas->update(['name' => 'Galletas nuevas', 'sale_price' => '9.99']);

    app('auth')->forgetGuards();
    $this->withToken($this->seller->createToken('t')->plainTextToken)->getJson("/api/v1/tickets/{$id}")
        ->assertJsonPath('data.lines.0.product_name', 'Galletas')
        ->assertJsonPath('data.lines.0.unit_price', '2.99');
});

it('rechaza productos inactivos, inexistentes o de otra empresa', function () {
    $inactivo = Product::factory()->service()->inactive()->create(['company_id' => $this->company->id]);
    $ajeno = Product::factory()->service()->create(['company_id' => Company::factory()->create()->id]);

    foreach ([$inactivo->id, $ajeno->id, 999999] as $productId) {
        ($this->sell)(['lines' => [['product_id' => $productId, 'quantity' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.product_id']);
    }
});

it('valida líneas, cantidades según la unidad, medio de pago y clave', function () {
    ($this->sell)(['lines' => []])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    ($this->sell)(['lines' => [['product_id' => $this->galletas->id, 'quantity' => '1.5']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
    ($this->sell)(['lines' => [['product_id' => $this->galletas->id, 'quantity' => '0']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
    ($this->sell)(['payment_method' => 'cheque', 'idempotency_key' => 'no-uuid', 'lines' => [['product_id' => $this->galletas->id, 'quantity' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['payment_method', 'idempotency_key']);
});

it('RF-012 el administrador también puede vender', function () {
    ($this->sell)(['lines' => [['product_id' => $this->delivery->id, 'quantity' => '1']]], $this->admin)
        ->assertCreated();
});

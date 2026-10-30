<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Ticket;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Str;

/*
 * T015 · HU-2 esc. 1 y 3, RF-003, RF-004, RF-006, A-88 (spec 017): el
 * ticket queda en el local donde se vende y descuenta su stock; el
 * correlativo sigue siendo de la empresa.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001', 'name' => 'Sucursal', 'address' => 'Jr. Unión 456']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->seller->establishments()->attach($this->branch->id, ['company_id' => $this->company->id]);
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'sale_price' => '5.00']);
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id]);
    ProductLot::factory()->for($this->product)->quantity('4')->create(['establishment_id' => $this->branch->id]);
    $this->sell = function (array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)->postJson('/api/v1/tickets', [
            'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1']], ...$data,
        ]);
    };
    $this->stock = fn (Establishment $e) => app(InventoryService::class)->balance($this->product, $e);
});

it('el vendedor con un solo local vende ahí sin decirlo, como hoy', function () {
    ($this->sell)()->assertCreated()
        ->assertJsonPath('data.establishment.code', '0001')
        ->assertJsonPath('data.establishment.address', 'Jr. Unión 456');

    expect(Ticket::withoutTenancy()->sole()->establishment_id)->toBe($this->branch->id)
        ->and(($this->stock)($this->branch))->toBe('3.000')
        ->and(($this->stock)($this->main))->toBe('10.000');
});

it('con varios locales hay que elegir uno', function () {
    ($this->sell)([], $this->admin)->assertUnprocessable()->assertJsonPath('errors.establishment_id.0', 'Elige el local.');

    ($this->sell)(['establishment_id' => $this->main->id], $this->admin)->assertCreated()->assertJsonPath('data.establishment.code', '0000');
    expect(($this->stock)($this->main))->toBe('9.000');
});

it('rechaza un local no asignado o inactivo sin crear nada', function () {
    ($this->sell)(['establishment_id' => $this->main->id])->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);

    $this->branch->update(['active' => false]);
    ($this->sell)(['establishment_id' => $this->branch->id])->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);

    expect(Ticket::withoutTenancy()->count())->toBe(0)
        ->and(($this->stock)($this->branch))->toBe('4.000');
});

it('el vendedor sin local activo no puede vender', function () {
    $this->branch->update(['active' => false]);

    ($this->sell)()->assertUnprocessable()
        ->assertJsonPath('errors.establishment_id.0', 'No tienes un local asignado. Pide al administrador que te asigne uno.');
});

it('sin stock en el local avisa con el disponible del local', function () {
    ($this->sell)(['lines' => [['product_id' => $this->product->id, 'quantity' => '5']]])
        ->assertUnprocessable()
        ->assertJsonPath('meta.available', '4.000');
});

it('A-88 el correlativo sigue siendo de la empresa en todos los locales', function () {
    ($this->sell)()->assertCreated()->assertJsonPath('data.number', 1);
    ($this->sell)(['establishment_id' => $this->main->id], $this->admin)->assertCreated()->assertJsonPath('data.number', 2);
    ($this->sell)()->assertCreated()->assertJsonPath('data.number', 3);
});

it('la lista trae el local de cada ticket, sin la dirección', function () {
    ($this->sell)()->assertCreated();
    app('auth')->forgetGuards();

    $row = $this->withToken($this->admin->createToken('t')->plainTextToken)->getJson('/api/v1/tickets')->assertOk()->json('data.0');

    expect($row['establishment'])->toBe(['id' => $this->branch->id, 'code' => '0001', 'name' => 'Sucursal']);
});

it('anular devuelve el stock al local del ticket', function () {
    $ticket = ($this->sell)()->assertCreated()->json('data.id');
    app('auth')->forgetGuards();

    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->postJson("/api/v1/tickets/{$ticket}/void", ['reason' => 'Error de cobro'])->assertOk();

    expect(($this->stock)($this->branch))->toBe('4.000');
});

<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;

/*
 * T014 · A-90, HU-4, RF-007 (spec 017): productos, lotes, movimientos y
 * alertas por local, o de todos los que el usuario puede leer.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create(['expiry_warning_days' => 30]);
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001', 'name' => 'Sucursal']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->seller->establishments()->attach($this->branch->id, ['company_id' => $this->company->id]);
    $this->product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id, 'name' => 'Arroz', 'min_stock' => '5']);
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id, 'lot_number' => 'P-1', 'expires_at' => today()->addYear()]);
    ProductLot::factory()->for($this->product)->quantity('3')->create(['establishment_id' => $this->branch->id, 'lot_number' => 'S-1', 'expires_at' => today()->addDays(10)]);
    $this->call = function (string $method, string $uri, array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
});

it('la lista de productos da el stock del local pedido o la suma de los permitidos', function () {
    $stock = fn (array $query = [], ?User $as = null) => ($this->call)('GET', '/api/v1/products', $query, $as)->assertOk()->json('data.0.stock');

    expect($stock())->toBe('13.000')
        ->and($stock(['establishment_id' => $this->branch->id]))->toBe('3.000')
        ->and($stock(['establishment_id' => $this->main->id]))->toBe('10.000')
        ->and($stock([], $this->seller))->toBe('3.000');

    ($this->call)('GET', '/api/v1/products', ['establishment_id' => $this->main->id], $this->seller)
        ->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);
});

it('la ficha trae el stock por local, solo de los permitidos', function () {
    $admin = ($this->call)('GET', "/api/v1/products/{$this->product->id}")->assertOk();
    $seller = ($this->call)('GET', "/api/v1/products/{$this->product->id}", [], $this->seller)->assertOk();

    expect($admin->json('data.stock_by_establishment'))->toBe([
        ['establishment_id' => $this->main->id, 'stock' => '10.000', 'available_stock' => '10.000'],
        ['establishment_id' => $this->branch->id, 'stock' => '3.000', 'available_stock' => '3.000'],
    ])->and($seller->json('data.stock_by_establishment'))->toBe([
        ['establishment_id' => $this->branch->id, 'stock' => '3.000', 'available_stock' => '3.000'],
    ])->and($seller->json('data.stock'))->toBe('3.000');
});

it('lotes y movimientos se filtran por local y traen su local', function () {
    $entry = ($this->call)('POST', "/api/v1/products/{$this->product->id}/entries", ['quantity' => '2', 'expires_at' => today()->addMonth()->toDateString(), 'establishment_id' => $this->branch->id])
        ->assertCreated()
        ->assertJsonPath('data.establishment.code', '0001')
        ->assertJsonPath('product_stock', '5.000');

    $lots = fn (array $q = [], ?User $as = null) => collect(($this->call)('GET', "/api/v1/products/{$this->product->id}/lots", $q, $as)->assertOk()->json('data'));
    $movements = fn (array $q = [], ?User $as = null) => collect(($this->call)('GET', "/api/v1/products/{$this->product->id}/movements", $q, $as)->assertOk()->json('data'));

    expect($lots()->pluck('lot_number')->sort()->values()->all())->toBe([$entry->json('data.lot_number'), 'P-1', 'S-1'])
        ->and($lots(['establishment_id' => $this->main->id])->pluck('lot_number')->all())->toBe(['P-1'])
        ->and($lots([], $this->seller)->pluck('establishment.name')->unique()->all())->toBe(['Sucursal'])
        // La factory de lotes registra su entrada: una por lote.
        ->and($movements(['establishment_id' => $this->branch->id])->pluck('establishment.code')->all())->toBe(['0001', '0001'])
        ->and($movements()->count())->toBe(3);
});

it('la entrada pide el local si hay varios y rechaza uno no permitido', function () {
    ($this->call)('POST', "/api/v1/products/{$this->product->id}/entries", ['quantity' => '1', 'expires_at' => today()->addMonth()->toDateString()])
        ->assertUnprocessable()->assertJsonPath('errors.establishment_id.0', 'Elige el local.');

    $this->branch->update(['active' => false]);
    ($this->call)('POST', "/api/v1/products/{$this->product->id}/entries", ['quantity' => '1', 'expires_at' => today()->addMonth()->toDateString(), 'establishment_id' => $this->branch->id])
        ->assertUnprocessable()->assertJsonPath('errors.establishment_id.0', 'No puedes trabajar en ese local.');
});

it('un movimiento de traslado dice de dónde y a dónde', function () {
    ($this->call)('POST', "/api/v1/products/{$this->product->id}/transfers", ['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '1'])->assertCreated();

    $out = collect(($this->call)('GET', "/api/v1/products/{$this->product->id}/movements")->json('data'))->firstWhere('type', 'transfer_out');

    expect($out['establishment']['code'])->toBe('0000')
        ->and($out['transfer'])->toBe([
            'from' => ['id' => $this->main->id, 'code' => '0000', 'name' => $this->main->name],
            'to' => ['id' => $this->branch->id, 'code' => '0001', 'name' => 'Sucursal'],
        ]);
});

it('las alertas son por local: un producto puede estar bajo el mínimo en uno solo', function () {
    $alerts = fn (array $q = []) => ($this->call)('GET', '/api/v1/inventory/alerts', $q)->assertOk()->json('data');

    $all = $alerts();
    expect(collect($all['low_stock'])->map(fn ($a) => [$a['name'], $a['establishment']['code'], $a['available_stock']])->all())->toBe([['Arroz', '0001', '3.000']])
        ->and(collect($all['expiring'])->map(fn ($a) => [$a['lot_number'], $a['establishment']['code']])->all())->toBe([['S-1', '0001']])
        ->and($alerts(['establishment_id' => $this->main->id]))->toBe(['low_stock' => [], 'expiring' => [], 'expired' => []]);
});

it('un local inactivo no da alertas de stock bajo, pero sí de lotes por vencer', function () {
    $this->branch->update(['active' => false]);

    $all = ($this->call)('GET', '/api/v1/inventory/alerts')->assertOk()->json('data');

    expect($all['low_stock'])->toBe([])
        ->and(collect($all['expiring'])->pluck('lot_number')->all())->toBe(['S-1']);
});

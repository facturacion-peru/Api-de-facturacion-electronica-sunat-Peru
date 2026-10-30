<?php

use App\Enums\MovementType;
use App\Inventory\Exceptions\InsufficientStock;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * T009 · A-86, HU-2 esc. 4, RF-005 (spec 017): el stock es de cada local.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);
    $this->actor = User::factory()->forCompany($this->company)->create();
    $this->product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id]);
    $this->inventory = app(InventoryService::class);
    app(TenantContext::class)->set($this->company);
});

it('una entrada crea el lote en el local indicado', function () {
    $lot = $this->inventory->registerEntry($this->product, $this->branch, ['quantity' => '5'], $this->actor);

    expect($lot->establishment_id)->toBe($this->branch->id)
        ->and(InventoryMovement::where('lot_id', $lot->id)->sole()->establishment_id)->toBe($this->branch->id)
        ->and($this->inventory->balance($this->product, $this->branch))->toBe('5.000')
        ->and($this->inventory->balance($this->product, $this->main))->toBe('0.000')
        ->and($this->inventory->balance($this->product))->toBe('5.000');
});

it('vender solo descuenta lotes del local, con FEFO entre ellos', function () {
    // El lote del principal vence antes, pero no es del local de la venta.
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id, 'lot_number' => 'P', 'expires_at' => today()->addDay()]);
    ProductLot::factory()->for($this->product)->quantity('4')->create(['establishment_id' => $this->branch->id, 'lot_number' => 'S-TARDE', 'expires_at' => today()->addMonth()]);
    ProductLot::factory()->for($this->product)->quantity('4')->create(['establishment_id' => $this->branch->id, 'lot_number' => 'S-PRONTO', 'expires_at' => today()->addWeek()]);

    $movements = DB::transaction(fn () => $this->inventory->consume($this->product, $this->branch, '5', null, $this->actor));

    expect($movements->pluck('lot.lot_number')->all())->toBe(['S-PRONTO', 'S-TARDE'])
        ->and($movements->pluck('establishment_id')->unique()->all())->toBe([$this->branch->id])
        ->and($this->inventory->balance($this->product, $this->main))->toBe('10.000')
        ->and($this->inventory->balance($this->product, $this->branch))->toBe('3.000');
});

it('sin stock en el local, informa el disponible del local aunque otro tenga', function () {
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id]);
    ProductLot::factory()->for($this->product)->quantity('2')->create(['establishment_id' => $this->branch->id]);

    try {
        DB::transaction(fn () => $this->inventory->consume($this->product, $this->branch, '3', null, $this->actor));
        $this->fail('Debió faltar stock');
    } catch (InsufficientStock $e) {
        expect($e->available)->toBe('2.000');
    }

    expect($this->inventory->balance($this->product, $this->branch))->toBe('2.000');
});

it('el saldo del producto en cada movimiento es el del local', function () {
    $this->inventory->registerEntry($this->product, $this->main, ['quantity' => '10'], $this->actor);
    $this->inventory->registerEntry($this->product, $this->branch, ['quantity' => '3'], $this->actor);
    DB::transaction(fn () => $this->inventory->consume($this->product, $this->branch, '1', null, $this->actor));

    $balances = InventoryMovement::orderBy('id')->get()->map(fn ($m) => [$m->type->value, $m->product_balance_after])->all();

    expect($balances)->toBe([['entry', '10.000'], ['entry', '3.000'], ['sale', '2.000']]);
});

it('la devolución de una nota repone en los lotes del local de la venta', function () {
    $series = App\Models\Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->branch->id]);
    $original = App\Models\SalesDocument::factory()->create(['series_id' => $series->id]);
    $note = App\Models\SalesDocument::factory()->create(['series_id' => $series->id, 'reference_document_id' => $original->id]);
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id]);
    ProductLot::factory()->for($this->product)->quantity('5')->create(['establishment_id' => $this->branch->id]);
    DB::transaction(fn () => $this->inventory->consume($this->product, $this->branch, '4', $original, $this->actor));

    $returned = DB::transaction(fn () => $this->inventory->restock($this->product, '4', $original, $note, $this->actor));

    expect($returned->pluck('establishment_id')->unique()->all())->toBe([$this->branch->id])
        ->and($returned->first()->type)->toBe(MovementType::Return)
        ->and($this->inventory->balance($this->product, $this->branch))->toBe('5.000')
        ->and($this->inventory->balance($this->product, $this->main))->toBe('10.000');
});

it('withStock suma solo los locales pedidos', function () {
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id]);
    ProductLot::factory()->for($this->product)->quantity('2')->create(['establishment_id' => $this->branch->id, 'expires_at' => today()->subDay()]);

    $only = fn (array $ids) => Product::query()->withStock($ids)->findOrFail($this->product->id);

    expect($only([$this->branch->id])->stock())->toBe('2.000')
        ->and($only([$this->branch->id])->availableStock())->toBe('0.000')
        ->and($only([$this->main->id, $this->branch->id])->stock())->toBe('12.000')
        ->and(Product::query()->withStock()->findOrFail($this->product->id)->stock())->toBe('12.000');
});

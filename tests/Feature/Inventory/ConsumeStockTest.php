<?php

use App\Enums\MovementType;
use App\Inventory\Exceptions\InsufficientStock;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/*
 * T040 · HU-3 Salida de stock al vender (RF-014, RF-015, RF-017, A-14).
 * La venta que la dispara llega con las specs 003 y 005; aquí se prueba la regla.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->actor = User::factory()->forCompany($this->company)->create();
    $this->inventory = app(InventoryService::class);
});

/** Como lo hará una venta: dentro de su transacción. */
function consume(Product $product, string $quantity, $source = null)
{
    return DB::transaction(fn () => test()->inventory->consume($product, $quantity, $source ?? test()->actor, test()->actor));
}

function lotRemaining(string $lotNumber): string
{
    return ProductLot::withoutTenancy()->where('lot_number', $lotNumber)->value('remaining_quantity');
}

it('HU-3.1 FEFO: sale primero el lote que vence antes', function () {
    $product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('10')->create(['lot_number' => 'TARDE', 'expires_at' => today()->addMonths(6), 'received_at' => today()->subDays(10)]);
    ProductLot::factory()->for($product)->quantity('10')->create(['lot_number' => 'PRONTO', 'expires_at' => today()->addWeek(), 'received_at' => today()->subDay()]);

    consume($product, '4');

    expect(lotRemaining('PRONTO'))->toBe('6.000')->and(lotRemaining('TARDE'))->toBe('10.000');
});

it('HU-3.2 FIFO: sale primero el lote que entró antes', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('10')->create(['lot_number' => 'NUEVO', 'received_at' => today()]);
    ProductLot::factory()->for($product)->quantity('10')->create(['lot_number' => 'VIEJO', 'received_at' => today()->subMonth()]);

    consume($product, '3');

    expect(lotRemaining('VIEJO'))->toBe('7.000')->and(lotRemaining('NUEVO'))->toBe('10.000');
});

it('HU-3.3 una venta consume varios lotes con un movimiento por lote', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('2')->create(['lot_number' => 'A', 'received_at' => today()->subDays(3)]);
    ProductLot::factory()->for($product)->quantity('5')->create(['lot_number' => 'B', 'received_at' => today()->subDays(2)]);

    $movements = consume($product, '4');

    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('quantity')->all())->toBe(['-2.000', '-2.000'])
        ->and($movements->every(fn ($m) => $m->type === MovementType::Sale))->toBeTrue()
        ->and(lotRemaining('A'))->toBe('0.000')
        ->and(lotRemaining('B'))->toBe('3.000')
        ->and($movements->last()->product_balance_after)->toBe('3.000');
});

it('HU-3.4 sin stock suficiente no descuenta nada e informa el disponible', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('3')->create(['lot_number' => 'A']);

    try {
        consume($product, '5');
        $this->fail('Debía lanzar InsufficientStock');
    } catch (InsufficientStock $e) {
        expect($e->available)->toBe('3.000')->and($e->requested)->toBe('5.000');
    }

    expect(lotRemaining('A'))->toBe('3.000')
        ->and(InventoryMovement::withoutTenancy()->where('type', MovementType::Sale)->count())->toBe(0);
});

it('HU-3.5 los lotes vencidos no cuentan para vender', function () {
    $product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('10')->create(['lot_number' => 'VENCIDO', 'expires_at' => today()->subDay()]);
    ProductLot::factory()->for($product)->quantity('2')->create(['lot_number' => 'VIGENTE', 'expires_at' => today()->addMonth()]);

    expect(fn () => consume($product, '3'))->toThrow(InsufficientStock::class);

    consume($product, '2');
    expect(lotRemaining('VENCIDO'))->toBe('10.000')->and(lotRemaining('VIGENTE'))->toBe('0.000');
});

it('los lotes sin fecha de un producto con vencimiento salen al final', function () {
    $product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('5')->create(['lot_number' => 'SIN-FECHA', 'received_at' => today()->subYear()]);
    ProductLot::factory()->for($product)->quantity('5')->create(['lot_number' => 'CON-FECHA', 'expires_at' => today()->addYear()]);

    consume($product, '1');

    expect(lotRemaining('CON-FECHA'))->toBe('4.000')->and(lotRemaining('SIN-FECHA'))->toBe('5.000');
});

it('los servicios no descuentan stock', function () {
    $service = Product::factory()->service()->create(['company_id' => $this->company->id]);

    expect(consume($service, '3'))->toBeEmpty()
        ->and(InventoryMovement::withoutTenancy()->count())->toBe(0);
});

it('admite fracciones en productos por peso', function () {
    $product = Product::factory()->byWeight()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('1.250')->create(['lot_number' => 'KG']);

    consume($product, '0.375');

    expect(lotRemaining('KG'))->toBe('0.875');
});

it('guarda el documento de origen y el responsable en cada movimiento', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('5')->create();
    $source = Company::factory()->create();

    $movement = consume($product, '1', $source)->first();

    expect($movement->source_type)->toBe($source->getMorphClass())
        ->and($movement->source_id)->toBe($source->id)
        ->and($movement->created_by)->toBe($this->actor->id);
});

it('rechaza cantidades no positivas', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);

    consume($product, '0');
})->throws(InvalidArgumentException::class);

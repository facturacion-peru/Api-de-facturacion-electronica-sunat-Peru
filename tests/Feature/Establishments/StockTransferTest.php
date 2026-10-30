<?php

use App\Enums\AdjustmentReason;
use App\Enums\CompanyRole;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/*
 * T011 · HU-3, RF-005, RF-008 (spec 017): trasladar mercadería entre locales.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001', 'name' => 'Sucursal']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id]);
    $this->call = function (array $data, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)
            ->postJson("/api/v1/products/{$this->product->id}/transfers", $data);
    };
    $this->balance = fn (Establishment $e) => app(InventoryService::class)->balance($this->product, $e);
});

it('HU-3 esc. 2 traslada con FEFO y el destino conserva lote, fechas y costo', function () {
    ProductLot::factory()->for($this->product)->quantity('3')->create([
        'establishment_id' => $this->main->id, 'lot_number' => 'PRONTO', 'expires_at' => today()->addWeek(), 'received_at' => today()->subDays(5), 'unit_cost' => '2.5000',
    ]);
    ProductLot::factory()->for($this->product)->quantity('10')->create([
        'establishment_id' => $this->main->id, 'lot_number' => 'TARDE', 'expires_at' => today()->addMonth(), 'received_at' => today()->subDays(2), 'unit_cost' => '3.0000',
    ]);

    $response = ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '5', 'note' => 'Reposición'])
        ->assertCreated()
        ->assertJsonPath('data.id', $this->product->id);

    expect(collect($response->json('data.stock_by_establishment'))->keyBy('establishment_id')->map(fn ($s) => $s['stock'])->all())
        ->toBe([$this->main->id => '8.000', $this->branch->id => '5.000']);

    $arrived = ProductLot::withoutTenancy()->where('establishment_id', $this->branch->id)->orderBy('lot_number')->get();
    expect($arrived->map(fn ($l) => [$l->lot_number, $l->remaining_quantity, $l->expires_at->toDateString(), $l->received_at->toDateString(), $l->unit_cost])->all())->toBe([
        ['PRONTO', '3.000', today()->addWeek()->toDateString(), today()->subDays(5)->toDateString(), '2.5000'],
        ['TARDE', '2.000', today()->addMonth()->toDateString(), today()->subDays(2)->toDateString(), '3.0000'],
    ]);

    $transfer = StockTransfer::withoutTenancy()->sole();
    $movements = InventoryMovement::withoutTenancy()->where('source_type', $transfer->getMorphClass())->where('source_id', $transfer->id)->orderBy('id')->get();
    expect($transfer->only(['from_establishment_id', 'to_establishment_id', 'quantity', 'note']))->toBe([
        'from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '5.000', 'note' => 'Reposición',
    ])->and($movements->map(fn ($m) => [$m->type, $m->quantity, $m->establishment_id])->all())->toBe([
        [MovementType::TransferOut, '-3.000', $this->main->id], [MovementType::TransferIn, '3.000', $this->branch->id],
        [MovementType::TransferOut, '-2.000', $this->main->id], [MovementType::TransferIn, '2.000', $this->branch->id],
    ])->and(AuditLog::withoutTenancy()->where('action', 'inventory.transfer')->sole()->changes)->toMatchArray([
        'from' => '0000', 'to' => '0001', 'quantity' => '5.000',
    ]);
});

it('suma al lote que ya existe en el destino', function () {
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id, 'lot_number' => 'L-1', 'expires_at' => today()->addMonth()]);
    ProductLot::factory()->for($this->product)->quantity('1')->create(['establishment_id' => $this->branch->id, 'lot_number' => 'L-1', 'expires_at' => today()->addMonth()]);

    ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '4'])->assertCreated();

    expect(ProductLot::withoutTenancy()->where('establishment_id', $this->branch->id)->sole()->only(['initial_quantity', 'remaining_quantity']))
        ->toBe(['initial_quantity' => '1.000', 'remaining_quantity' => '5.000']);
});

it('no traslada lotes vencidos', function () {
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id, 'expires_at' => today()->subDay()]);

    ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
});

it('HU-3 esc. 3 sin stock suficiente en el origen no cambia nada', function () {
    ProductLot::factory()->for($this->product)->quantity('2')->create(['establishment_id' => $this->main->id]);

    ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '3'])
        ->assertUnprocessable()->assertJsonPath('errors.quantity.0', "Solo hay 2.000 disponible de {$this->product->name}.");

    expect(($this->balance)($this->main))->toBe('2.000')
        ->and(($this->balance)($this->branch))->toBe('0.000')
        ->and(StockTransfer::withoutTenancy()->count())->toBe(0);
});

it('no traslada a un local inactivo, pero sí saca el stock de uno inactivo', function () {
    ProductLot::factory()->for($this->product)->quantity('5')->create(['establishment_id' => $this->branch->id]);
    $this->branch->update(['active' => false]);

    ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['to_establishment_id']);

    ($this->call)(['from_establishment_id' => $this->branch->id, 'to_establishment_id' => $this->main->id, 'quantity' => '5'])->assertCreated();

    expect(($this->balance)($this->main))->toBe('5.000');
});

it('valida los locales y la cantidad', function (array $data, string $field) {
    if (($data['to_establishment_id'] ?? null) === 'from') {
        $data['to_establishment_id'] = $this->main->id;
    }

    ($this->call)([...['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '1'], ...$data])
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'mismo local' => [['to_establishment_id' => 'from'], 'to_establishment_id'],
    'sin cantidad' => [['quantity' => null], 'quantity'],
    'cantidad cero' => [['quantity' => '0'], 'quantity'],
    'origen inexistente' => [['from_establishment_id' => 999999], 'from_establishment_id'],
]);

it('un servicio no tiene stock que trasladar', function () {
    $this->product->update(['type' => 'service']);

    ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['product']);
});

it('el vendedor no traslada', function () {
    ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '1'], $this->seller)
        ->assertForbidden();
});

it('un movimiento de traslado no se revierte: se corrige con otro traslado', function () {
    ProductLot::factory()->for($this->product)->quantity('5')->create(['establishment_id' => $this->main->id]);
    ($this->call)(['from_establishment_id' => $this->main->id, 'to_establishment_id' => $this->branch->id, 'quantity' => '2'])->assertCreated();
    app(TenantContext::class)->set($this->company);

    foreach (InventoryMovement::whereIn('type', [MovementType::TransferOut, MovementType::TransferIn])->get() as $movement) {
        expect(fn () => app(InventoryService::class)->reverse($movement, AdjustmentReason::Error, null, $this->admin))
            ->toThrow(ValidationException::class, 'traslado');
    }
});

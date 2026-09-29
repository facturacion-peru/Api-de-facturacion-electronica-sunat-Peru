<?php

use App\Enums\AdjustmentReason;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/*
 * T060 · HU-5 Historial y alertas (RF-021).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create(['expiry_warning_days' => 30]);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create(['name' => 'Ana']);
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->get = function (string $uri, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->getJson($uri);
    };
});

it('HU-5.1 muestra el historial con tipo, lote, saldos, responsable y origen', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    $lot = ProductLot::factory()->for($product)->quantity('10')->create(['lot_number' => 'L-1']);
    $inventory = app(InventoryService::class);
    DB::transaction(fn () => $inventory->consume($product, '3', $this->company, $this->admin));
    $inventory->adjust($lot, '-1', AdjustmentReason::Damage, 'Golpeado', $this->admin);

    $response = ($this->get)("/api/v1/products/{$product->id}/movements")->assertOk();
    $latest = $response->json('data.0');

    expect(collect($response->json('data'))->pluck('type')->all())->toBe(['adjustment', 'sale', 'entry'])
        ->and($latest['lot']['lot_number'])->toBe('L-1')
        ->and($latest['lot_balance_after'])->toBe('6.000')
        ->and($latest['product_balance_after'])->toBe('6.000')
        ->and($latest['reason'])->toBe('damage')
        ->and($latest['created_by']['name'])->toBe('Ana')
        ->and($response->json('data.1.source'))->toBe(['type' => 'company', 'id' => $this->company->id])
        ->and($response->json('meta.total'))->toBe(3);
});

it('HU-5.2 alerta el stock bajo cuando el disponible llega al mínimo', function () {
    $bajo = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Aceite', 'min_stock' => '5']);
    ProductLot::factory()->for($bajo)->quantity('5')->create();
    $ok = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Sal', 'min_stock' => '5']);
    ProductLot::factory()->for($ok)->quantity('6')->create();
    Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Sin mínimo']);
    Product::factory()->inactive()->create(['company_id' => $this->company->id, 'name' => 'Inactivo', 'min_stock' => '5']);

    $low = ($this->get)('/api/v1/inventory/alerts')->assertOk()->json('data.low_stock');

    expect(collect($low)->pluck('name')->all())->toBe(['Aceite'])
        ->and($low[0])->toMatchArray(['available_stock' => '5.000', 'min_stock' => '5.000']);
});

it('HU-5.3 alerta los lotes que vencen dentro del plazo de la empresa y los vencidos con saldo', function () {
    $product = Product::factory()->tracksExpiry()->create(['company_id' => $this->company->id, 'name' => 'Yogur']);
    ProductLot::factory()->for($product)->create(['lot_number' => 'PRONTO', 'expires_at' => today()->addDays(10)]);
    ProductLot::factory()->for($product)->create(['lot_number' => 'LEJOS', 'expires_at' => today()->addDays(60)]);
    ProductLot::factory()->for($product)->create(['lot_number' => 'VENCIDO', 'expires_at' => today()->subDay()]);

    $data = ($this->get)('/api/v1/inventory/alerts')->json('data');

    expect(collect($data['expiring'])->pluck('lot_number')->all())->toBe(['PRONTO'])
        ->and($data['expiring'][0]['days_left'])->toBe(10)
        ->and($data['expiring'][0]['product']['name'])->toBe('Yogur')
        ->and(collect($data['expired'])->pluck('lot_number')->all())->toBe(['VENCIDO']);

    $this->company->update(['expiry_warning_days' => 90]);

    expect(collect(($this->get)('/api/v1/inventory/alerts')->json('data.expiring'))->pluck('lot_number')->sort()->values()->all())
        ->toBe(['LEJOS', 'PRONTO']);
});

it('el administrador configura los días de aviso de vencimiento', function () {
    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->patchJson('/api/v1/company', ['expiry_warning_days' => 45])
        ->assertOk()
        ->assertJsonPath('data.expiry_warning_days', 45);

    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->patchJson('/api/v1/company', ['expiry_warning_days' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['expiry_warning_days']);
});

it('el vendedor no ve historial ni alertas', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);

    ($this->get)("/api/v1/products/{$product->id}/movements", $this->seller)->assertForbidden();
    ($this->get)('/api/v1/inventory/alerts', $this->seller)->assertForbidden();
});

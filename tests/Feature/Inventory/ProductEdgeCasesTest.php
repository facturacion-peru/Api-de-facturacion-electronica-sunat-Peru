<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;

/*
 * T022 · Casos límite del catálogo (spec 002).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->token = $admin->createToken('t')->plainTextToken;
    $this->patch = function (Product $product, array $data) {
        app('auth')->forgetGuards();

        return $this->withToken($this->token)->patchJson("/api/v1/products/{$product->id}", $data);
    };
});

it('no convierte en servicio un bien con stock', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('3')->create();

    ($this->patch)($product, ['type' => 'service', 'unit' => 'ZZ'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.type.0', 'No se puede convertir en servicio un producto con stock.');
});

it('sí convierte en servicio un bien sin stock', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);

    ($this->patch)($product, ['type' => 'service', 'unit' => 'ZZ'])->assertOk()->assertJsonPath('data.type', 'service');
});

it('las cantidades con decimales solo valen si la unidad lo permite', function () {
    $unidad = Product::factory()->create(['company_id' => $this->company->id]);
    $kilo = Product::factory()->byWeight()->create(['company_id' => $this->company->id]);

    ($this->patch)($unidad, ['min_stock' => '2.5'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.min_stock.0', 'Esta unidad de medida no admite decimales.');

    ($this->patch)($kilo, ['min_stock' => '2.5'])->assertOk()->assertJsonPath('data.min_stock', '2.500');
    ($this->patch)($unidad, ['min_stock' => '2', 'unit' => 'KGM'])->assertOk();
});

it('activar el control de vencimiento deja sin fecha los lotes existentes', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    $lot = ProductLot::factory()->for($product)->create();

    ($this->patch)($product, ['tracks_expiry' => true])->assertOk()->assertJsonPath('data.tracks_expiry', true);

    expect($lot->fresh()->expires_at)->toBeNull();
});

it('desactivar un producto con stock se permite y avisa', function () {
    $product = Product::factory()->create(['company_id' => $this->company->id]);
    ProductLot::factory()->for($product)->quantity('8')->create();

    ($this->patch)($product, ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('warning', 'El producto queda desactivado con 8.000 en stock.');
});

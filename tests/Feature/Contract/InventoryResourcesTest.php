<?php

use App\Enums\CompanyRole;
use App\Http\Resources\LotResource;
use App\Http\Resources\MovementResource;
use App\Http\Resources\ProductResource;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/*
 * T072 · Contrato de los recursos de inventario (principio III), en sus dos
 * variantes: con costo (administrador) y sin costo (vendedor, RF-022).
 */

function requestAs(CompanyRole $role, ?Company $company = null): Request
{
    $user = User::factory()->forCompany($company ?? Company::factory()->create(), $role)->create()->load('membership');
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

$productKeys = ['id', 'code', 'name', 'type', 'unit', 'sale_price', 'igv_affectation', 'min_stock', 'tracks_expiry', 'active', 'image', 'stock', 'available_stock'];
$lotKeys = ['id', 'lot_number', 'received_at', 'expires_at', 'expired', 'initial_quantity', 'remaining_quantity'];

it('ProductResource para el administrador incluye el costo', function () use ($productKeys) {
    $product = Product::factory()->create();
    app(TenantContext::class)->set($product->company);

    expect(array_keys(ProductResource::make($product)->resolve(requestAs(CompanyRole::CompanyAdmin, $product->company))))
        ->toBe([...$productKeys, 'last_unit_cost', 'created_at']);
});

it('ProductResource para el vendedor omite el costo', function () use ($productKeys) {
    $product = Product::factory()->create();
    app(TenantContext::class)->set($product->company);

    expect(array_keys(ProductResource::make($product)->resolve(requestAs(CompanyRole::Seller, $product->company))))
        ->toBe([...$productKeys, 'created_at']);
});

it('ProductResource lleva la foto como {url, thumb_url} o null (spec 016)', function () {
    $product = Product::factory()->create(['image_key' => '0b6f3c2e-6d1a-4c3e-9f7a-2b8d5e4c1a90']);
    app(TenantContext::class)->set($product->company);
    $request = requestAs(CompanyRole::Seller, $product->company);

    expect(ProductResource::make($product)->resolve($request)['image'])
        ->toBeArray()
        ->toHaveKeys(['url', 'thumb_url'])
        ->toHaveCount(2)
        ->and(ProductResource::make($product->forceFill(['image_key' => null]))->resolve($request)['image'])->toBeNull();
});

it('LotResource incluye el costo solo para el administrador', function () use ($lotKeys) {
    $lot = ProductLot::factory()->create()->load('creator');

    expect(array_keys(LotResource::make($lot)->resolve(requestAs(CompanyRole::CompanyAdmin))))
        ->toBe([...$lotKeys, 'unit_cost', 'reference', 'created_by', 'created_at'])
        ->and(array_keys(LotResource::make($lot)->resolve(requestAs(CompanyRole::Seller))))
        ->toBe([...$lotKeys, 'reference', 'created_by', 'created_at']);
});

it('MovementResource expone exactamente los campos declarados', function () {
    $lot = ProductLot::factory()->create();
    app(TenantContext::class)->set($lot->company);
    $movement = InventoryMovement::where('lot_id', $lot->id)->sole()->load(['lot', 'creator']);

    expect(array_keys(MovementResource::make($movement)->resolve()))->toBe([
        'id', 'type', 'quantity', 'lot', 'lot_balance_after', 'product_balance_after', 'reason', 'note',
        'source', 'reverses_id', 'reversed', 'created_by', 'created_at',
    ]);
});

<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;

/*
 * T020 · HU-1 Catálogo de productos (RF-001, RF-002, RF-022).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    // Cada llamada simula una petición nueva: el guard no conserva al usuario anterior.
    $this->asAdmin = function () {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    };
    $this->asSeller = function () {
        app('auth')->forgetGuards();

        return $this->withToken($this->seller->createToken('t')->plainTextToken);
    };
    $this->payload = [
        'code' => 'ARZ-001',
        'name' => 'Arroz extra 5 kg',
        'type' => 'good',
        'unit' => 'NIU',
        'sale_price' => '25.90',
        'igv_affectation' => '10',
        'min_stock' => '5',
        'tracks_expiry' => true,
    ];
});

function productIn(Company $company, array $attributes = []): Product
{
    return Product::factory()->create(['company_id' => $company->id, ...$attributes]);
}

it('HU-1.1 el administrador registra un producto', function () {
    ($this->asAdmin)()->postJson('/api/v1/products', $this->payload)
        ->assertCreated()
        ->assertJsonPath('data.code', 'ARZ-001')
        ->assertJsonPath('data.sale_price', '25.90')
        ->assertJsonPath('data.igv_affectation', '10')
        ->assertJsonPath('data.tracks_expiry', true)
        ->assertJsonPath('data.stock', '0.000');

    expect(Product::withoutTenancy()->where('code', 'ARZ-001')->value('company_id'))->toBe($this->company->id)
        ->and(AuditLog::withoutTenancy()->where('action', 'product.created')->count())->toBe(1);
});

it('registra un servicio sin stock', function () {
    ($this->asAdmin)()->postJson('/api/v1/products', [...$this->payload, 'type' => 'service', 'unit' => 'ZZ', 'min_stock' => null, 'tracks_expiry' => false])
        ->assertCreated()
        ->assertJsonPath('data.type', 'service')
        ->assertJsonPath('data.stock', null);
});

it('valida los campos obligatorios y los catálogos', function () {
    ($this->asAdmin)()->postJson('/api/v1/products', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'name', 'type', 'unit', 'sale_price', 'igv_affectation']);

    ($this->asAdmin)()->postJson('/api/v1/products', [...$this->payload, 'unit' => 'XYZ', 'igv_affectation' => '40', 'sale_price' => '-1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unit', 'igv_affectation', 'sale_price']);
});

it('un servicio no controla vencimiento ni stock mínimo', function () {
    ($this->asAdmin)()->postJson('/api/v1/products', [...$this->payload, 'type' => 'service', 'unit' => 'ZZ'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tracks_expiry', 'min_stock']);
});

it('HU-1.2 rechaza un código repetido en la empresa, pero lo admite en otra', function () {
    productIn($this->company, ['code' => 'ARZ-001']);
    productIn(Company::factory()->create(), ['code' => 'ARZ-002']);

    ($this->asAdmin)()->postJson('/api/v1/products', $this->payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'Ya existe un producto con este código.');

    ($this->asAdmin)()->postJson('/api/v1/products', [...$this->payload, 'code' => 'ARZ-002'])->assertCreated();
});

it('edita un producto y audita el cambio', function () {
    $product = productIn($this->company, ['sale_price' => '10.00']);

    ($this->asAdmin)()->patchJson("/api/v1/products/{$product->id}", ['sale_price' => '12.50'])
        ->assertOk()
        ->assertJsonPath('data.sale_price', '12.50');

    $log = AuditLog::withoutTenancy()->where('action', 'product.updated')->sole();
    expect($log->changes['sale_price'])->toBe(['from' => '10.00', 'to' => '12.50']);
});

it('HU-1.3 un producto desactivado no aparece para vender y conserva su historial', function () {
    $product = productIn($this->company, ['name' => 'Fideos']);
    ProductLot::factory()->for($product)->create();

    ($this->asAdmin)()->patchJson("/api/v1/products/{$product->id}", ['active' => false])->assertOk();

    expect(collect(($this->asSeller)()->getJson('/api/v1/products')->json('data'))->pluck('name'))->not->toContain('Fideos')
        ->and(collect(($this->asSeller)()->getJson('/api/v1/products?status=all')->json('data'))->pluck('name'))->not->toContain('Fideos')
        ->and(collect(($this->asAdmin)()->getJson('/api/v1/products?status=inactive')->json('data'))->pluck('name'))->toContain('Fideos')
        ->and($product->movements()->count())->toBe(1);
});

it('HU-1.4 el vendedor ve precio y stock, pero no el costo', function () {
    $product = productIn($this->company);
    ProductLot::factory()->for($product)->quantity('8')->create(['unit_cost' => '3.2500']);

    $asSeller = ($this->asSeller)()->getJson("/api/v1/products/{$product->id}")->assertOk();
    expect($asSeller->json('data'))->not->toHaveKey('last_unit_cost')
        ->and($asSeller->json('data.stock'))->toBe('8.000');

    ($this->asAdmin)()->getJson("/api/v1/products/{$product->id}")
        ->assertJsonPath('data.last_unit_cost', '3.2500');
});

it('HU-1.4 el vendedor no crea ni edita productos', function () {
    $product = productIn($this->company);

    ($this->asSeller)()->postJson('/api/v1/products', $this->payload)->assertForbidden();
    ($this->asSeller)()->patchJson("/api/v1/products/{$product->id}", ['name' => 'X'])->assertForbidden();
});

it('busca por código o nombre, filtra por tipo y pagina', function () {
    productIn($this->company, ['code' => 'LEC-01', 'name' => 'Leche evaporada']);
    productIn($this->company, ['code' => 'AZU-01', 'name' => 'Azúcar rubia']);
    Product::factory()->service()->create(['company_id' => $this->company->id, 'code' => 'DEL-01', 'name' => 'Delivery']);

    $names = fn (string $query) => collect(($this->asSeller)()->getJson('/api/v1/products?'.$query)->assertOk()->json('data'))->pluck('name')->all();

    expect($names('search=leche'))->toBe(['Leche evaporada'])
        ->and($names('search=AZU'))->toBe(['Azúcar rubia'])
        ->and($names('type=service'))->toBe(['Delivery']);

    expect(($this->asSeller)()->getJson('/api/v1/products')->json('meta'))->toHaveKeys(['current_page', 'total', 'last_page']);
});

it('el listado incluye stock y disponible sin consultas por producto', function () {
    $product = productIn($this->company);
    ProductLot::factory()->for($product)->quantity('4')->create();
    ProductLot::factory()->for($product)->quantity('6')->create(['expires_at' => today()->subDay()]);

    ($this->asSeller)()->getJson('/api/v1/products')
        ->assertJsonPath('data.0.stock', '10.000')
        ->assertJsonPath('data.0.available_stock', '4.000');
});

it('ofrece los catálogos de unidades, afectaciones y motivos', function () {
    $catalogs = ($this->asSeller)()->getJson('/api/v1/catalogs/inventory')->assertOk()->json('data');

    expect(collect($catalogs['units'])->firstWhere('code', 'KGM'))->toMatchArray(['label' => 'Kilogramo', 'allows_decimals' => true])
        ->and(collect($catalogs['igv_affectations'])->pluck('code')->all())->toBe(['10', '20', '30'])
        ->and($catalogs['adjustment_reasons'])->not->toBeEmpty();
});

<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\ImportFiles;

/*
 * T020 · A-90 (spec 017): el stock inicial de la importación de productos va
 * al local elegido en la vista previa; por defecto, al principal.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->call = function (string $method, string $uri, array $data = []) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
    $this->import = function (array $data = []) {
        $file = ImportFiles::make(['codigo', 'nombre', 'tipo', 'unidad', 'precio_venta', 'afectacion_igv', 'stock_inicial'], [['IMP-1', 'Fideos', 'bien', 'NIU', '3.50', '10', '12']]);
        $preview = ($this->call)('POST', '/api/v1/imports/products/preview', ['file' => $file, ...$data]);

        return $preview;
    };
    $this->lotOf = function () {
        app(TenantContext::class)->set($this->company);

        return ProductLot::where('product_id', Product::where('code', 'IMP-1')->value('id'))->sole();
    };
});

it('el stock inicial va al local elegido', function () {
    $id = ($this->import)(['establishment_id' => $this->branch->id])->assertCreated()->json('data.id');
    ($this->call)('POST', "/api/v1/imports/{$id}/confirm")->assertOk();

    expect(($this->lotOf)()->only(['establishment_id', 'remaining_quantity']))->toBe(['establishment_id' => $this->branch->id, 'remaining_quantity' => '12.000']);
});

it('sin local, el stock inicial va al principal', function () {
    $id = ($this->import)()->assertCreated()->json('data.id');
    ($this->call)('POST', "/api/v1/imports/{$id}/confirm")->assertOk();

    expect(($this->lotOf)()->establishment_id)->toBe($this->main->id);
});

it('rechaza un local inactivo en la vista previa', function () {
    $this->branch->update(['active' => false]);

    ($this->import)(['establishment_id' => $this->branch->id])->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);
});

it('si el local se desactiva antes de confirmar, no importa nada (409)', function () {
    $id = ($this->import)(['establishment_id' => $this->branch->id])->assertCreated()->json('data.id');
    $this->branch->update(['active' => false]);

    ($this->call)('POST', "/api/v1/imports/{$id}/confirm")->assertStatus(409);

    app(TenantContext::class)->set($this->company);
    expect(Product::where('code', 'IMP-1')->exists())->toBeFalse();
});

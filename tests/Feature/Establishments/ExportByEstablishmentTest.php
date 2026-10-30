<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\Ticket;
use App\Models\User;
use Tests\Support\SpreadsheetFile;

/*
 * T022 · A-90, HU-4 esc. 3 (spec 017): las exportaciones traen la columna
 * «local» y se filtran por local; el stock de productos es la suma de los
 * locales pedidos.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001', 'name' => 'Sucursal']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'ARZ-1']);
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->main->id, 'lot_number' => 'P-1']);
    ProductLot::factory()->for($this->product)->quantity('3')->create(['establishment_id' => $this->branch->id, 'lot_number' => 'S-1']);
    Ticket::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->main->id]);
    Ticket::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->branch->id]);
    SalesDocument::factory()->create(['series_id' => Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->branch->id, 'code' => 'B002'])->id]);
    $this->export = function (string $kind, array $query = []) {
        app('auth')->forgetGuards();

        return SpreadsheetFile::sheets($this->withToken($this->admin->createToken('t')->plainTextToken)
            ->get("/api/v1/exports/{$kind}?".http_build_query($query))->assertOk());
    };
});

it('productos: el stock es la suma de los locales pedidos y cada lote dice su local', function () {
    $all = ($this->export)('products', ['lots' => 1]);
    $branch = ($this->export)('products', ['lots' => 1, 'establishment_id' => $this->branch->id]);

    expect($all['productos'][0]['stock_actual'])->toBe('13')
        ->and(collect($all['lotes'])->map(fn ($r) => [$r['lote'], $r['local']])->all())->toBe([['P-1', '0000 Domicilio fiscal'], ['S-1', '0001 Sucursal']])
        ->and($branch['productos'][0]['stock_actual'])->toBe('3')
        ->and(collect($branch['lotes'])->pluck('lote')->all())->toBe(['S-1']);
});

it('ventas: la columna local y el filtro', function () {
    $range = ['from' => today()->toDateString(), 'to' => today()->toDateString()];
    $all = ($this->export)('sales', $range);
    $branch = ($this->export)('sales', [...$range, 'establishment_id' => $this->branch->id]);

    expect(collect($all['documentos'])->pluck('local')->sort()->values()->all())->toBe(['0000 Domicilio fiscal', '0001 Sucursal', '0001 Sucursal'])
        ->and(collect($branch['documentos'])->pluck('local')->unique()->values()->all())->toBe(['0001 Sucursal'])
        ->and($branch['documentos'])->toHaveCount(2);
});

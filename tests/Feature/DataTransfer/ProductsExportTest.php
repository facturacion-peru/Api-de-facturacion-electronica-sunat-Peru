<?php

use App\Enums\CompanyRole;
use App\Enums\IgvAffectation;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\SpreadsheetFile;

/*
 * Spec 014 · HU-1: exportar productos y stock (T009).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($this->company);

    $this->rice = Product::factory()->create([
        'company_id' => $this->company->id, 'code' => '0012', 'name' => 'Arroz «Costeño» 1 kg', 'sale_price' => '4.50',
        'unit' => UnitOfMeasure::Unit, 'min_stock' => '5', 'tracks_expiry' => true,
    ]);
    ProductLot::factory()->for($this->rice)->create(['lot_number' => 'L-1', 'initial_quantity' => '10', 'remaining_quantity' => '10', 'unit_cost' => '3.2000', 'expires_at' => '2027-01-31', 'received_at' => '2026-10-01']);
    ProductLot::factory()->for($this->rice)->create(['lot_number' => 'L-2', 'initial_quantity' => '5', 'remaining_quantity' => '2.5', 'unit_cost' => '3.5000', 'expires_at' => '2027-03-31', 'received_at' => '2026-10-02']);
    ProductLot::factory()->for($this->rice)->create(['lot_number' => 'L-agotado', 'initial_quantity' => '4', 'remaining_quantity' => '0', 'unit_cost' => '9.0000']);

    $this->service = Product::factory()->create([
        'company_id' => $this->company->id, 'code' => 'SERV-1', 'name' => '=Delivery', 'type' => ProductType::Service,
        'unit' => UnitOfMeasure::Service, 'sale_price' => '5.00', 'igv_affectation' => IgvAffectation::Exonerado,
    ]);
    $this->inactive = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'VIEJO', 'name' => 'Producto inactivo', 'active' => false]);

    $this->export = function (array $query = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)
            ->get('/api/v1/exports/products?'.http_build_query($query));
    };
});

afterEach(fn () => Carbon::setTestNow());

it('HU-1.1 exporta el catálogo en XLSX con stock y costo del stock', function () {
    $response = ($this->export)(['status' => 'all']);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($response->headers->get('content-disposition'))->toContain('productos-2026-10-07.xlsx');

    $rows = collect(SpreadsheetFile::sheets($response)['productos'])->keyBy('codigo');
    // El orden por nombre depende de la intercalación de la base: se compara sin orden.
    expect($rows->keys()->sort()->values()->all())->toBe(['0012', 'SERV-1', 'VIEJO'])
        ->and($rows['0012'])->toBe([
            'codigo' => '0012', 'nombre' => 'Arroz «Costeño» 1 kg', 'tipo' => 'bien', 'unidad' => 'NIU',
            'precio_venta' => '4.5', 'afectacion_igv' => '10', 'stock_minimo' => '5', 'controla_vencimiento' => 'si',
            'activo' => 'si', 'stock_actual' => '12.5', 'costo_stock' => '40.75', // 10 × 3.20 + 2.5 × 3.50
        ])
        ->and($rows['SERV-1']['nombre'])->toBe('=Delivery')
        ->and($rows['SERV-1']['tipo'])->toBe('servicio')
        ->and($rows['SERV-1']['afectacion_igv'])->toBe('20')
        ->and($rows['SERV-1']['stock_actual'])->toBe('')
        ->and($rows['VIEJO']['activo'])->toBe('no');
});

it('HU-1.2 con lotes agrega la hoja de lotes con saldo', function () {
    $sheets = SpreadsheetFile::sheets(($this->export)(['lots' => 1]));

    expect(array_keys($sheets))->toBe(['productos', 'lotes'])
        ->and($sheets['lotes'])->toBe([
            ['codigo' => '0012', 'nombre' => 'Arroz «Costeño» 1 kg', 'lote' => 'L-1', 'ingreso' => '2026-10-01', 'vencimiento' => '2027-01-31', 'cantidad_restante' => '10', 'costo_unitario' => '3.2'],
            ['codigo' => '0012', 'nombre' => 'Arroz «Costeño» 1 kg', 'lote' => 'L-2', 'ingreso' => '2026-10-02', 'vencimiento' => '2027-03-31', 'cantidad_restante' => '2.5', 'costo_unitario' => '3.5'],
        ]);
});

it('HU-1.2 en CSV con lotes entrega un ZIP con un CSV por hoja, y neutraliza fórmulas', function () {
    $response = ($this->export)(['format' => 'csv', 'lots' => 1]);

    expect($response->headers->get('content-disposition'))->toContain('productos-2026-10-07.zip');
    $sheets = SpreadsheetFile::sheets($response);
    expect(array_keys($sheets))->toBe(['productos', 'lotes'])
        // La lectura quita el apóstrofo; el contenido crudo lo lleva.
        ->and(collect($sheets['productos'])->firstWhere('codigo', 'SERV-1')['nombre'])->toBe('=Delivery')
        ->and(collect($sheets['productos'])->firstWhere('codigo', '0012')['precio_venta'])->toBe('4.50');
});

it('en CSV sin lotes entrega un solo CSV con BOM y el apóstrofo delante de la fórmula', function () {
    $response = ($this->export)(['format' => 'csv']);

    $content = $response->streamedContent();
    expect($response->headers->get('content-disposition'))->toContain('productos-2026-10-07.csv')
        ->and($content)->toStartWith("\xEF\xBB\xBFcodigo,nombre,tipo")
        ->and($content)->toContain("SERV-1,'=Delivery,servicio");
});

it('HU-1.3 respeta los filtros del listado', function () {
    $codes = fn (array $query) => collect(SpreadsheetFile::sheets(($this->export)($query))['productos'])->pluck('codigo')->sort()->values()->all();

    expect($codes([]))->toBe(['0012', 'SERV-1'])                    // por defecto, activos
        ->and($codes(['status' => 'inactive']))->toBe(['VIEJO'])
        ->and($codes(['type' => 'service']))->toBe(['SERV-1'])
        ->and($codes(['search' => 'costeño']))->toBe(['0012']);
});

it('un catálogo vacío exporta solo los encabezados', function () {
    $response = ($this->export)(['search' => 'no-existe']);

    $response->assertOk();
    expect(SpreadsheetFile::sheets($response)['productos'])->toBe([]);
});

it('RF-010 deja la exportación en la auditoría con formato, filtros y filas', function () {
    ($this->export)(['format' => 'csv', 'search' => 'arroz'])->assertOk();

    $log = AuditLog::query()->where('action', 'export.products')->sole();
    expect($log->actor_id)->toBe($this->admin->id)
        ->and($log->company_id)->toBe($this->company->id)
        ->and($log->changes)->toMatchArray(['format' => 'csv', 'rows' => 1, 'filters' => ['status' => 'active', 'type' => null, 'search' => 'arroz', 'lots' => false]]);
});

it('valida el formato', function () {
    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->getJson('/api/v1/exports/products?format=pdf')
        ->assertUnprocessable()->assertJsonValidationErrors('format');
});

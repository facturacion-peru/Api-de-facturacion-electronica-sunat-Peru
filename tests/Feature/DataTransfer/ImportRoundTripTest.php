<?php

use App\Enums\CompanyRole;
use App\Enums\CustomerDocumentType;
use App\Enums\IgvAffectation;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Tests\Support\ImportFiles;

/*
 * Spec 014 · T023: CE-003 (una fila inválida entre 2 000 no guarda nada) y
 * CE-004 (exportar y reimportar en «crear y actualizar» no cambia nada).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($this->company);

    $this->call = function (string $method, string $uri, array $data = []) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
    /** Descarga una exportación como archivo subible. */
    $this->download = function (string $uri): UploadedFile {
        app('auth')->forgetGuards();
        $response = $this->withToken($this->admin->createToken('t')->plainTextToken)->get($uri);
        preg_match('/filename="?([^";]+)"?/', (string) $response->headers->get('content-disposition'), $match);

        return ImportFiles::raw($response->streamedContent(), $match[1]);
    };
});

it('CE-003 un error en la fila 2 000 no guarda nada', function () {
    $rows = array_map(fn (int $i) => ["P-{$i}", "Producto {$i}", 'bien', 'NIU', '1.00', '10'], range(1, 2000));
    $rows[1999][4] = '1.005';

    $preview = ($this->call)('POST', '/api/v1/imports/products/preview', [
        'file' => ImportFiles::make(['codigo', 'nombre', 'tipo', 'unidad', 'precio_venta', 'afectacion_igv'], $rows, 'csv'),
    ]);

    $preview->assertCreated()
        ->assertJsonPath('data.can_confirm', false)
        ->assertJsonPath('data.summary.rows', 2000)
        ->assertJsonPath('data.summary.errors', 1)
        ->assertJsonPath('data.errors.0.row', 2001)
        ->assertJsonPath('data.errors.0.column', 'precio_venta');

    ($this->call)('POST', '/api/v1/imports/'.$preview->json('data.id').'/confirm')->assertUnprocessable();
    expect(Product::query()->count())->toBe(0);
});

it('CE-004 exportar productos y reimportarlos no produce cambios', function (string $format) {
    $rice = Product::factory()->create(['company_id' => $this->company->id, 'code' => '0012', 'name' => 'Arroz «Costeño»', 'sale_price' => '4.50', 'min_stock' => '5', 'tracks_expiry' => true]);
    ProductLot::factory()->for($rice)->create(['initial_quantity' => '10', 'remaining_quantity' => '10', 'unit_cost' => '3.2', 'expires_at' => '2027-01-31']);
    Product::factory()->create(['company_id' => $this->company->id, 'code' => 'K-1', 'name' => '=Azúcar a granel', 'unit' => UnitOfMeasure::Kilogram, 'sale_price' => '3.80', 'min_stock' => '0.5']);
    Product::factory()->create(['company_id' => $this->company->id, 'code' => 'SERV', 'name' => 'Delivery', 'type' => ProductType::Service, 'unit' => UnitOfMeasure::Service, 'igv_affectation' => IgvAffectation::Inafecto, 'sale_price' => '0']);
    Product::factory()->create(['company_id' => $this->company->id, 'code' => 'OLD', 'name' => 'Inactivo', 'active' => false]);

    $file = ($this->download)("/api/v1/exports/products?status=all&format={$format}");
    $preview = ($this->call)('POST', '/api/v1/imports/products/preview', ['file' => $file, 'mode' => 'upsert']);

    $preview->assertCreated()
        ->assertJsonPath('data.summary', ['rows' => 4, 'create' => 0, 'update' => 0, 'unchanged' => 4, 'errors' => 0])
        ->assertJsonPath('data.changes', []);
    expect(array_column($preview->json('data.warnings'), 'column'))->toBe(['stock_actual', 'costo_stock']);
})->with(['xlsx', 'csv']);

it('CE-004 exportar clientes y reimportarlos no produce cambios', function (string $format) {
    Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::Dni, 'document_number' => '01234567', 'name' => 'Ana Pérez', 'address' => null]);
    Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::Ruc, 'document_number' => '20131312955', 'name' => 'Bodega Ñaña', 'address' => '+51 Jr. Lima']);
    Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::ForeignerCard, 'document_number' => 'X1234567', 'name' => 'John', 'address' => 'Av. 1']);

    $file = ($this->download)("/api/v1/exports/customers?format={$format}");
    ($this->call)('POST', '/api/v1/imports/customers/preview', ['file' => $file, 'mode' => 'upsert'])
        ->assertCreated()
        ->assertJsonPath('data.summary', ['rows' => 3, 'create' => 0, 'update' => 0, 'unchanged' => 3, 'errors' => 0])
        ->assertJsonPath('data.warnings', []);
})->with(['xlsx', 'csv']);

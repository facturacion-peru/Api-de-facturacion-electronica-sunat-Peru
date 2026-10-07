<?php

use App\Enums\CompanyRole;
use App\Enums\UnitOfMeasure;
use App\Models\Company;
use App\Models\ImportPreview;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Support\ImportFiles;

/*
 * Spec 014 · HU-4 (T017): vista previa de la importación de productos.
 * Valida con las reglas del alta manual y no guarda nada del catálogo.
 */

const PRODUCT_HEADERS = ['codigo', 'nombre', 'tipo', 'unidad', 'precio_venta', 'afectacion_igv', 'stock_minimo', 'controla_vencimiento', 'activo', 'stock_inicial', 'costo_unitario', 'lote', 'vencimiento'];

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($this->company);

    $this->oil = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'ACE-1', 'name' => 'Aceite 1 l', 'sale_price' => '3.50']);
    ProductLot::factory()->for($this->oil)->create(['initial_quantity' => '4', 'remaining_quantity' => '4']);

    $this->preview = function (UploadedFile $file, string $mode = 'create') {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->postJson('/api/v1/imports/products/preview', ['file' => $file, 'mode' => $mode]);
    };
    /** Fila completa con valores válidos, sobrescribiendo lo indicado. */
    $this->row = fn (array $values = []) => array_values(array_merge(
        array_fill_keys(PRODUCT_HEADERS, ''),
        ['codigo' => 'ARR-1', 'nombre' => 'Arroz 1 kg', 'tipo' => 'bien', 'unidad' => 'NIU', 'precio_venta' => '4.50', 'afectacion_igv' => '10'],
        $values,
    ));
});

afterEach(fn () => Carbon::setTestNow());

function errorsOf($response): array
{
    return collect($response->json('data.errors'))->map(fn ($e) => "{$e['row']}:{$e['column']}")->all();
}

it('HU-4.2 valida, resume y guarda la vista previa sin tocar el catálogo', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(),
        ($this->row)(['codigo' => '0012', 'nombre' => 'Delivery', 'tipo' => 'servicio', 'unidad' => 'ZZ', 'precio_venta' => '5', 'afectacion_igv' => '20']),
    ]));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.kind', 'products')
        ->assertJsonPath('data.mode', 'create')
        ->assertJsonPath('data.can_confirm', true)
        ->assertJsonPath('data.summary', ['rows' => 2, 'create' => 2, 'update' => 0, 'unchanged' => 0, 'errors' => 0])
        ->assertJsonPath('data.errors', [])
        ->assertJsonPath('data.expires_at', '2026-10-07T10:30:00-05:00');
    expect($response->json('data.changes'))->toBe([
        ['row' => 2, 'action' => 'create', 'key' => 'ARR-1', 'name' => 'Arroz 1 kg', 'stock' => null],
        ['row' => 3, 'action' => 'create', 'key' => '0012', 'name' => 'Delivery', 'stock' => null],
    ]);

    expect(Product::query()->count())->toBe(1)
        ->and(ImportPreview::query()->sole()->user_id)->toBe($this->admin->id);
});

it('HU-4.4 en «solo crear» un código existente es error', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [($this->row)(['codigo' => 'ACE-1'])]));

    $response->assertCreated()->assertJsonPath('data.can_confirm', false)->assertJsonPath('data.summary.errors', 1);
    expect($response->json('data.errors'))->toBe([['row' => 2, 'column' => 'codigo', 'message' => 'Ya existe un producto con este código.']]);
});

it('HU-4.5 en «crear y actualizar» muestra cada cambio campo por campo y cuenta los que no cambian', function () {
    $other = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'SAL-1', 'name' => 'Sal', 'sale_price' => '1.20', 'unit' => UnitOfMeasure::Unit]);

    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(['codigo' => 'ACE-1', 'nombre' => 'Aceite 1 l', 'precio_venta' => '3,80']),
        ($this->row)(['codigo' => 'SAL-1', 'nombre' => 'Sal', 'precio_venta' => '1.2']),
        ($this->row)(['codigo' => 'NUEVO']),
    ]), 'upsert');

    $response->assertCreated()->assertJsonPath('data.summary', ['rows' => 3, 'create' => 1, 'update' => 1, 'unchanged' => 1, 'errors' => 0]);
    expect($response->json('data.changes'))->toBe([
        ['row' => 2, 'action' => 'update', 'key' => 'ACE-1', 'name' => 'Aceite 1 l', 'fields' => ['precio_venta' => ['from' => '3.50', 'to' => '3.80']]],
        ['row' => 4, 'action' => 'create', 'key' => 'NUEVO', 'name' => 'Arroz 1 kg', 'stock' => null],
    ])->and($this->oil->fresh()->sale_price)->toBe('3.50');
});

it('marca como error las dos filas de un código repetido en el archivo', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(['codigo' => 'ARR-1']),
        ($this->row)(['codigo' => 'OTRO']),
        ($this->row)(['codigo' => 'ARR-1']),
    ]));

    expect(errorsOf($response))->toBe(['2:codigo', '4:codigo'])
        ->and($response->json('data.errors.0.message'))->toBe('El código ARR-1 se repite en las filas 2 y 4.');
});

it('valida cada fila con las reglas del alta manual, con el nombre de la columna', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(['codigo' => 'A', 'tipo' => 'producto']),
        ($this->row)(['codigo' => 'B', 'precio_venta' => '3.805']),
        ($this->row)(['codigo' => 'C', 'afectacion_igv' => '40']),
        ($this->row)(['codigo' => 'D', 'unidad' => 'litros']),
        ($this->row)(['codigo' => 'E', 'stock_minimo' => '1.5']),          // NIU no admite decimales
        ($this->row)(['codigo' => 'F', 'controla_vencimiento' => 'quizás']),
        ($this->row)(['codigo' => '', 'nombre' => '']),
    ]));

    expect(errorsOf($response))->toBe(['2:tipo', '3:precio_venta', '4:afectacion_igv', '5:unidad', '6:stock_minimo', '7:controla_vencimiento', '8:codigo', '8:nombre'])
        ->and($response->json('data.errors.1.message'))->toContain('precio_venta');
});

it('acepta unidades por código o nombre, «sí», coma decimal y fechas de Excel', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(['codigo' => 'K', 'unidad' => 'Kilogramo', 'stock_minimo' => '0,5', 'controla_vencimiento' => 'Sí', 'activo' => 'no',
            'stock_inicial' => '12,25', 'costo_unitario' => '2,1234', 'vencimiento' => '31/12/2026']),
        ($this->row)(['codigo' => 'L', 'unidad' => 'ltr']),
    ]));

    $response->assertCreated()->assertJsonPath('data.summary.errors', 0)->assertJsonPath('data.changes.0.stock', '12.25');
    $stored = ImportPreview::query()->sole()->rows;
    expect($stored[0]['data'])->toMatchArray(['unit' => 'KGM', 'min_stock' => '0.5', 'tracks_expiry' => true, 'active' => false])
        ->and($stored[0]['entry'])->toMatchArray(['quantity' => '12.25', 'unit_cost' => '2.1234', 'expires_at' => '2026-12-31'])
        ->and($stored[1]['data']['unit'])->toBe('LTR');
});

it('A-72 el stock inicial se valida como una entrada: servicio, vencimiento y cantidad', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(['codigo' => 'S', 'tipo' => 'servicio', 'unidad' => 'ZZ', 'stock_inicial' => '5']),
        ($this->row)(['codigo' => 'V', 'controla_vencimiento' => 'si', 'stock_inicial' => '5']),
        ($this->row)(['codigo' => 'P', 'controla_vencimiento' => 'si', 'stock_inicial' => '5', 'vencimiento' => '2026-10-01']),
        ($this->row)(['codigo' => 'N', 'stock_inicial' => '0']),
        ($this->row)(['codigo' => 'C', 'costo_unitario' => '2.00']),
    ]));

    expect(errorsOf($response))->toBe(['2:stock_inicial', '3:vencimiento', '4:vencimiento', '5:stock_inicial', '6:stock_inicial']);
});

it('A-72 en un producto existente las columnas de stock se ignoran con aviso', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(['codigo' => 'ACE-1', 'nombre' => 'Aceite 1 l', 'precio_venta' => '3.50', 'stock_inicial' => '10']),
    ]), 'upsert');

    $response->assertCreated()->assertJsonPath('data.summary.errors', 0)->assertJsonPath('data.summary.unchanged', 1);
    expect($response->json('data.warnings'))->toBe([['row' => 2, 'column' => 'stock_inicial', 'message' => 'El producto ya existe: el stock no se cambia al importar (usa entradas o ajustes).']]);
});

it('ignora con aviso las columnas que no conoce y las de solo exportación', function () {
    $response = ($this->preview)(ImportFiles::make([...array_slice(PRODUCT_HEADERS, 0, 6), 'stock_actual', 'color'], [
        ['ARR-1', 'Arroz 1 kg', 'bien', 'NIU', '4.50', '10', '7', 'rojo'],
    ]));

    $response->assertCreated()->assertJsonPath('data.summary.errors', 0);
    expect($response->json('data.warnings'))->toBe([
        ['row' => null, 'column' => 'stock_actual', 'message' => 'Columna de solo lectura: se ignora.'],
        ['row' => null, 'column' => 'color', 'message' => 'Columna desconocida: se ignora.'],
    ]);
});

it('reconoce los encabezados con mayúsculas, tildes o espacios', function () {
    $response = ($this->preview)(ImportFiles::make(['Código', 'Nombre', 'TIPO', 'Unidad', 'Precio venta', 'Afectación IGV'], [
        ['ARR-1', 'Arroz 1 kg', 'bien', 'NIU', '4.50', '10'],
    ]));

    $response->assertCreated()->assertJsonPath('data.summary.create', 1)->assertJsonPath('data.warnings', []);
});

it('lee CSV de Excel (punto y coma, Windows-1252) y conserva el número de fila', function () {
    $csv = mb_convert_encoding("codigo;nombre;tipo;unidad;precio_venta;afectacion_igv\n\nAZU-1;Azúcar;bien;NIU;3,20;10\n", 'Windows-1252', 'UTF-8');
    $response = ($this->preview)(ImportFiles::raw($csv, 'productos.csv'));

    $response->assertCreated()->assertJsonPath('data.changes.0', ['row' => 3, 'action' => 'create', 'key' => 'AZU-1', 'name' => 'Azúcar', 'stock' => null]);
});

it('rechaza el archivo entero si falta una columna obligatoria, está vacío, dañado o es muy grande', function (Closure $file, string $message) {
    ($this->preview)($file())->assertUnprocessable()->assertJsonPath('errors.file.0', $message);
    expect(ImportPreview::query()->count())->toBe(0);
})->with([
    'sin precio_venta' => [fn () => ImportFiles::make(['codigo', 'nombre', 'tipo', 'unidad', 'afectacion_igv'], [['A', 'B', 'bien', 'NIU', '10']]), 'Falta la columna «precio_venta». Usa la plantilla.'],
    'solo encabezados' => [fn () => ImportFiles::make(PRODUCT_HEADERS, []), 'El archivo no tiene filas para importar.'],
    'dañado (se rechaza por su contenido)' => [fn () => ImportFiles::raw("PK\x03\x04roto", 'productos.xlsx'), 'El archivo debe ser un XLSX o un CSV.'],
    '2 001 filas' => [fn () => ImportFiles::make(PRODUCT_HEADERS, array_map(fn ($i) => ["P{$i}", 'X', 'bien', 'NIU', '1', '10', '', '', '', '', '', '', ''], range(1, 2001)), 'csv'), 'El archivo tiene más de 2 000 filas. Divídelo en varios archivos.'],
]);

it('rechaza archivos de más de 5 MB o de otro tipo', function () {
    ($this->preview)(UploadedFile::fake()->create('productos.xlsx', 5121))->assertUnprocessable()->assertJsonValidationErrors('file');
    ($this->preview)(UploadedFile::fake()->create('foto.png', 10, 'image/png'))->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('el modo debe ser create o upsert', function () {
    ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [($this->row)()]), 'replace')->assertUnprocessable()->assertJsonValidationErrors('mode');
});

it('cambiar a servicio un producto con stock es error, como en la edición manual', function () {
    $response = ($this->preview)(ImportFiles::make(PRODUCT_HEADERS, [
        ($this->row)(['codigo' => 'ACE-1', 'nombre' => 'Aceite 1 l', 'tipo' => 'servicio', 'unidad' => 'ZZ', 'precio_venta' => '3.50']),
    ]), 'upsert');

    expect(errorsOf($response))->toBe(['2:tipo']);
});

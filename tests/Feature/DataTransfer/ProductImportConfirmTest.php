<?php

use App\Enums\CompanyRole;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ImportPreview;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\ImportFiles;

/*
 * Spec 014 · HU-4 (T019): confirmar la importación de productos. Se aplica
 * todo o nada, con los mismos servicios y la misma auditoría que el alta
 * manual; la vista previa solo se confirma una vez y antes de caducar.
 */

const CONFIRM_HEADERS = ['codigo', 'nombre', 'tipo', 'unidad', 'precio_venta', 'afectacion_igv', 'controla_vencimiento', 'stock_inicial', 'costo_unitario', 'lote', 'vencimiento'];

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($this->company);

    $this->oil = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'ACE-1', 'name' => 'Aceite 1 l', 'sale_price' => '3.50']);
    ProductLot::factory()->for($this->oil)->create(['initial_quantity' => '4', 'remaining_quantity' => '4']);

    $this->call = function (string $method, string $uri, array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
    $this->previewOf = function (array $rows, string $mode = 'create', ?User $as = null) {
        $response = ($this->call)('POST', '/api/v1/imports/products/preview', ['file' => ImportFiles::make(CONFIRM_HEADERS, $rows), 'mode' => $mode], $as);
        $response->assertCreated();

        return $response->json('data.id');
    };
    $this->confirm = fn (string $id, ?User $as = null) => ($this->call)('POST', "/api/v1/imports/{$id}/confirm", [], $as);
});

afterEach(fn () => Carbon::setTestNow());

it('HU-4.6 y HU-4.8 crea los productos y el stock inicial como una entrada normal, con auditoría', function () {
    $id = ($this->previewOf)([
        ['ARR-1', 'Arroz 1 kg', 'bien', 'NIU', '4.50', '10', 'si', '20', '3.2', 'L-77', '2027-03-31'],
        ['DEL-1', 'Delivery', 'servicio', 'ZZ', '5', '20', '', '', '', '', ''],
    ]);

    ($this->confirm)($id)->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', ['created' => 2, 'updated' => 0, 'entries' => 1]);

    $rice = Product::query()->where('code', 'ARR-1')->sole();
    expect($rice->name)->toBe('Arroz 1 kg')->and($rice->sale_price)->toBe('4.50')->and($rice->tracks_expiry)->toBeTrue()
        ->and($rice->stock())->toBe('20.000');
    $lot = ProductLot::query()->where('product_id', $rice->id)->sole();
    expect($lot->lot_number)->toBe('L-77')->and($lot->unit_cost)->toBe('3.2000')->and($lot->expires_at->toDateString())->toBe('2027-03-31')
        ->and($lot->created_by)->toBe($this->admin->id);
    expect(InventoryMovement::query()->where('product_id', $rice->id)->sole()->type)->toBe(MovementType::Entry);

    $actions = AuditLog::query()->where('actor_id', $this->admin->id)->pluck('action')->all();
    expect(array_count_values($actions))->toMatchArray(['product.created' => 2, 'inventory.entry' => 1, 'import.confirmed' => 1]);
    expect(AuditLog::query()->where('action', 'import.confirmed')->sole()->changes)
        ->toBe(['preview' => $id, 'kind' => 'products', 'mode' => 'create', 'rows' => 2, 'created' => 2, 'updated' => 0, 'entries' => 1]);
    expect(ImportPreview::query()->sole()->confirmed_at)->not->toBeNull();
});

it('HU-4.5 y A-72 «crear y actualizar» cambia solo lo indicado y nunca el stock', function () {
    $id = ($this->previewOf)([['ACE-1', 'Aceite 1 l', 'bien', 'NIU', '3.80', '10', '', '50', '', '', '']], 'upsert');

    ($this->confirm)($id)->assertOk()->assertJsonPath('data', ['created' => 0, 'updated' => 1, 'entries' => 0]);

    expect($this->oil->fresh()->sale_price)->toBe('3.80')
        ->and($this->oil->fresh()->stock())->toBe('4.000')
        ->and(ProductLot::query()->where('product_id', $this->oil->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'product.updated')->sole()->changes)->toBe(['sale_price' => ['from' => '3.50', 'to' => '3.80']]);
});

it('HU-4.3 no se confirma una vista previa con errores', function () {
    $id = ($this->previewOf)([['ACE-1', 'Aceite', 'bien', 'NIU', '1', '10', '', '', '', '', '']]);

    ($this->confirm)($id)->assertUnprocessable()
        ->assertJsonPath('message', 'La vista previa tiene errores: corrige el archivo y vuelve a subirlo.');
    expect(Product::query()->count())->toBe(1);
});

it('una vista previa caducada o ya confirmada responde 410', function () {
    $id = ($this->previewOf)([['ARR-1', 'Arroz', 'bien', 'NIU', '1', '10', '', '', '', '', '']]);
    ($this->confirm)($id)->assertOk();
    ($this->confirm)($id)->assertStatus(410)->assertJsonPath('message', 'Esta importación ya se confirmó.');
    expect(Product::query()->where('code', 'ARR-1')->count())->toBe(1);

    $late = ($this->previewOf)([['ARR-2', 'Arroz', 'bien', 'NIU', '1', '10', '', '', '', '', '']]);
    $this->travel(31)->minutes();
    ($this->confirm)($late)->assertStatus(410)->assertJsonPath('message', 'La vista previa caducó. Vuelve a subir el archivo.');
    expect(Product::query()->where('code', 'ARR-2')->exists())->toBeFalse();
});

it('solo la confirma quien la creó: otro administrador de la empresa recibe 404', function () {
    $id = ($this->previewOf)([['ARR-1', 'Arroz', 'bien', 'NIU', '1', '10', '', '', '', '', '']]);
    $other = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();

    ($this->confirm)($id, $other)->assertNotFound();
    ($this->confirm)('00000000-0000-0000-0000-000000000000')->assertNotFound();
    expect(Product::query()->where('code', 'ARR-1')->exists())->toBeFalse();
});

it('el vendedor no puede confirmar', function () {
    $id = ($this->previewOf)([['ARR-1', 'Arroz', 'bien', 'NIU', '1', '10', '', '', '', '', '']]);
    $seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();

    ($this->confirm)($id, $seller)->assertForbidden();
});

it('409 si un código se creó después de la vista previa: no se aplica ninguna fila', function () {
    $id = ($this->previewOf)([
        ['ARR-1', 'Arroz', 'bien', 'NIU', '1', '10', '', '5', '', '', ''],
        ['FID-1', 'Fideos', 'bien', 'NIU', '1', '10', '', '', '', '', ''],
    ]);
    Product::factory()->create(['company_id' => $this->company->id, 'code' => 'FID-1']);

    ($this->confirm)($id)->assertConflict()
        ->assertJsonPath('message', 'Los productos cambiaron desde la vista previa: vuelve a subir el archivo para revisarla de nuevo.');

    expect(Product::query()->where('code', 'ARR-1')->exists())->toBeFalse()
        ->and(ProductLot::query()->count())->toBe(1)
        ->and(ImportPreview::query()->sole()->confirmed_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'import.confirmed')->exists())->toBeFalse();
});

it('409 si un producto que se actualiza cambió después de la vista previa', function () {
    $id = ($this->previewOf)([['ACE-1', 'Aceite 1 l', 'bien', 'NIU', '3.80', '10', '', '', '', '', '']], 'upsert');
    $this->travel(5)->seconds();
    $this->oil->update(['name' => 'Aceite vegetal 1 l']);

    ($this->confirm)($id)->assertConflict();
    expect($this->oil->fresh()->sale_price)->toBe('3.50');
});

<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\User;
use Database\Factories\EstablishmentFactory;

/*
 * T006 · HU-1, RF-001, RF-008, A-87, A-89 (spec 017): el administrador
 * registra, edita y desactiva los locales y asigna los de cada vendedor.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->seller->establishments()->attach($this->main->id, ['company_id' => $this->company->id]);
    $this->call = function (string $method, string $uri, array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
    $this->valid = ['code' => '0001', 'name' => 'Sucursal Centro', 'address' => 'Jr. Unión 456', 'ubigeo' => EstablishmentFactory::LIMA];
});

it('HU-1 esc. 1 crea un local activo con su código de anexo', function () {
    ($this->call)('POST', '/api/v1/establishments', $this->valid)
        ->assertCreated()
        ->assertJsonPath('data.code', '0001')
        ->assertJsonPath('data.name', 'Sucursal Centro')
        ->assertJsonPath('data.address', 'Jr. Unión 456')
        ->assertJsonPath('data.ubigeo', EstablishmentFactory::LIMA)
        ->assertJsonPath('data.district', 'Lima / Lima / Lima')
        ->assertJsonPath('data.is_main', false)
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.code_locked', false);

    expect(AuditLog::withoutTenancy()->where('action', 'establishment.created')->count())->toBe(1);
});

it('HU-1 esc. 2 rechaza un código repetido o que no tiene 4 dígitos', function (string $code) {
    ($this->call)('POST', '/api/v1/establishments', [...$this->valid, 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
})->with(['repetido' => '0000', 'corto' => '001', 'letras' => '00A1', 'largo' => '00011']);

it('un código usado en otra empresa sí se acepta', function () {
    $other = Company::factory()->withMainEstablishment()->create();
    Establishment::factory()->create(['company_id' => $other->id, 'code' => '0001']);

    ($this->call)('POST', '/api/v1/establishments', $this->valid)->assertCreated();
});

it('valida nombre, dirección y ubigeo', function () {
    ($this->call)('POST', '/api/v1/establishments', ['code' => '0001', 'ubigeo' => '999999'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'address', 'ubigeo']);
});

it('HU-1 esc. 3 edita nombre y dirección sin tocar los comprobantes ya emitidos', function () {
    $series = Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->main->id]);
    $document = SalesDocument::factory()->create(['series_id' => $series->id, 'issuer_address' => 'AV. ANTIGUA 1']);

    ($this->call)('PATCH', "/api/v1/establishments/{$this->main->id}", ['name' => 'Tienda principal', 'address' => 'Av. Nueva 2'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Tienda principal')
        ->assertJsonPath('data.address', 'Av. Nueva 2');

    expect($document->fresh()->issuer_address)->toBe('AV. ANTIGUA 1')
        ->and(AuditLog::withoutTenancy()->where('action', 'establishment.updated')->count())->toBe(1);
});

it('el código no cambia si el local ya tiene comprobantes', function () {
    $local = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);
    $series = Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $local->id]);
    SalesDocument::factory()->create(['series_id' => $series->id]);

    ($this->call)('GET', '/api/v1/establishments')->assertJsonPath('data.1.code_locked', true);
    ($this->call)('PATCH', "/api/v1/establishments/{$local->id}", ['code' => '0005'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    ($this->call)('PATCH', "/api/v1/establishments/{$local->id}", ['code' => '0001', 'name' => 'Mismo código'])->assertOk();
});

it('el código de un local sin comprobantes se puede corregir', function () {
    $local = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);

    ($this->call)('PATCH', "/api/v1/establishments/{$local->id}", ['code' => '0003'])->assertOk()->assertJsonPath('data.code', '0003');
});

it('HU-1 esc. 4 desactiva y reactiva un local, con su auditoría', function () {
    $local = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);

    ($this->call)('PATCH', "/api/v1/establishments/{$local->id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    ($this->call)('PATCH', "/api/v1/establishments/{$local->id}", ['active' => true])->assertOk()->assertJsonPath('data.active', true);

    expect(AuditLog::withoutTenancy()->where('action', 'establishment.deactivated')->count())->toBe(1)
        ->and(AuditLog::withoutTenancy()->where('action', 'establishment.activated')->count())->toBe(1);
});

it('el principal no se desactiva', function () {
    ($this->call)('PATCH', "/api/v1/establishments/{$this->main->id}", ['active' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['active']);

    expect($this->main->fresh()->active)->toBeTrue();
});

it('lista los locales permitidos; el administrador pide también los inactivos', function () {
    $closed = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0002', 'active' => false]);
    $other = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);

    expect(collect(($this->call)('GET', '/api/v1/establishments')->assertOk()->json('data'))->pluck('code')->all())->toBe(['0000', '0001'])
        ->and(collect(($this->call)('GET', '/api/v1/establishments', ['include_inactive' => 1])->json('data'))->pluck('code')->all())->toBe(['0000', '0001', '0002'])
        ->and(collect(($this->call)('GET', '/api/v1/establishments', ['include_inactive' => 1], $this->seller)->assertOk()->json('data'))->pluck('code')->all())->toBe(['0000']);
});

it('HU-1 esc. 6 el vendedor no crea, edita ni asigna locales', function () {
    ($this->call)('POST', '/api/v1/establishments', $this->valid, $this->seller)->assertForbidden();
    ($this->call)('PATCH', "/api/v1/establishments/{$this->main->id}", ['name' => 'X'], $this->seller)->assertForbidden();
    ($this->call)('PUT', "/api/v1/users/{$this->seller->id}/establishments", ['establishment_ids' => [$this->main->id]], $this->seller)->assertForbidden();
});

it('A-87 asigna los locales de un vendedor, con su auditoría', function () {
    $local = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);

    ($this->call)('PUT', "/api/v1/users/{$this->seller->id}/establishments", ['establishment_ids' => [$local->id]])
        ->assertOk()
        ->assertJsonPath('data.establishment_ids', [$local->id]);

    expect($this->seller->establishments()->pluck('establishments.id')->all())->toBe([$local->id])
        ->and(AuditLog::withoutTenancy()->where('action', 'user.establishments_changed')->sole()->changes)
        ->toBe(['from' => ['0000'], 'to' => ['0001']]);

    expect(collect(($this->call)('GET', '/api/v1/users')->json('data'))->firstWhere('id', $this->seller->id)['establishment_ids'])->toBe([$local->id]);
});

it('la asignación pide al menos un local activo de la empresa', function (Closure $ids) {
    ($this->call)('PUT', "/api/v1/users/{$this->seller->id}/establishments", ['establishment_ids' => $ids($this)])
        ->assertUnprocessable()->assertJsonValidationErrors(['establishment_ids']);

    expect($this->seller->establishments()->pluck('establishments.id')->all())->toBe([$this->main->id]);
})->with([
    'vacía' => [fn () => []],
    'solo inactivos' => [fn ($t) => [Establishment::factory()->create(['company_id' => $t->company->id, 'code' => '0009', 'active' => false])->id]],
    'de otra empresa' => [fn () => [Establishment::factory()->create(['company_id' => Company::factory()->create()->id])->id]],
]);

it('solo se asignan locales a vendedores', function () {
    ($this->call)('PUT', "/api/v1/users/{$this->admin->id}/establishments", ['establishment_ids' => [$this->main->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['user']);
});

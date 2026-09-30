<?php

use App\Enums\CompanyRole;
use App\Enums\TaxRegime;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Series;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
 * T040 · HU-3 Series y correlativos (RF-020 a RF-025, A-21).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->call = function (string $method, string $uri, array $data = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
});

it('HU-3.1 crea una serie continuando la numeración anterior', function () {
    ($this->call)('POST', '/api/v1/series', ['document_type' => '01', 'code' => 'f001', 'last_number' => 150])
        ->assertCreated()
        ->assertJsonPath('data.code', 'F001')
        ->assertJsonPath('data.document_type', '01')
        ->assertJsonPath('data.last_number', 150)
        ->assertJsonPath('data.next_number', 151)
        ->assertJsonPath('data.active', true);

    expect(Series::withoutTenancy()->sole()->establishment_id)->not->toBeNull()
        ->and(AuditLog::withoutTenancy()->where('action', 'series.created')->count())->toBe(1);
});

it('sin último número empieza en 1', function () {
    ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001'])
        ->assertCreated()->assertJsonPath('data.next_number', 1);
});

it('HU-3.2 valida el formato según el tipo de comprobante', function (string $type, string $code) {
    ($this->call)('POST', '/api/v1/series', ['document_type' => $type, 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
})->with([
    'factura con B' => ['01', 'B001'],
    'boleta con F' => ['03', 'F001'],
    'muy corta' => ['01', 'F01'],
    'muy larga' => ['03', 'B0011'],
    'con símbolos' => ['01', 'F-01'],
]);

it('HU-3.3 rechaza una serie repetida en la empresa, pero no en otra', function () {
    ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001'])->assertCreated();
    ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001'])
        ->assertUnprocessable()->assertJsonPath('errors.code.0', 'Ya existe la serie B001 en tu empresa.');

    app(TenantContext::class)->clear();
    $otra = Company::factory()->withMainEstablishment()->create();
    $adminOtra = User::factory()->forCompany($otra, CompanyRole::CompanyAdmin)->create();
    ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001'], $adminOtra)->assertCreated();
});

it('HU-3.4 se desactiva y reactiva, pero el correlativo no se edita y no se borra', function () {
    $id = ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001', 'last_number' => 20])->json('data.id');

    ($this->call)('PATCH', "/api/v1/series/{$id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    ($this->call)('PATCH', "/api/v1/series/{$id}", ['active' => true])->assertOk()->assertJsonPath('data.active', true);
    ($this->call)('PATCH', "/api/v1/series/{$id}", ['last_number' => 5, 'code' => 'B002'])
        ->assertUnprocessable()->assertJsonValidationErrors(['last_number', 'code']);
    ($this->call)('DELETE', "/api/v1/series/{$id}")->assertStatus(405);

    expect(Series::withoutTenancy()->find($id)->last_number)->toBe(20)
        ->and(AuditLog::withoutTenancy()->whereIn('action', ['series.deactivated', 'series.activated'])->count())->toBe(2);
});

it('HU-3.5 el listado muestra último y siguiente número, también al vendedor', function () {
    ($this->call)('POST', '/api/v1/series', ['document_type' => '01', 'code' => 'F001', 'last_number' => 7]);
    ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001']);

    $response = ($this->call)('GET', '/api/v1/series', [], $this->seller)->assertOk();

    expect(collect($response->json('data'))->pluck('code')->all())->toBe(['F001', 'B001'])
        ->and($response->json('data.0.next_number'))->toBe(8);
});

it('HU-3.6 una empresa del Nuevo RUS no crea series de factura', function () {
    $this->company->update(['tax_regime' => TaxRegime::Nrus]);

    ($this->call)('POST', '/api/v1/series', ['document_type' => '01', 'code' => 'F001'])
        ->assertUnprocessable()->assertJsonPath('errors.document_type.0', 'Las empresas del Nuevo RUS no emiten facturas.');
    ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001'])->assertCreated();
});

it('el vendedor no crea ni desactiva series', function () {
    $id = ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001'])->json('data.id');

    ($this->call)('POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B002'], $this->seller)->assertForbidden();
    ($this->call)('PATCH', "/api/v1/series/{$id}", ['active' => false], $this->seller)->assertForbidden();
});

it('007 crea series de nota de crédito con la letra del comprobante que modifican', function () {
    ($this->call)('POST', '/api/v1/series', ['document_type' => '07', 'code' => 'fc01'])
        ->assertCreated()->assertJsonPath('data.code', 'FC01')->assertJsonPath('data.document_type_label', 'Nota de crédito');
    ($this->call)('POST', '/api/v1/series', ['document_type' => '07', 'code' => 'BC01'])->assertCreated();
    ($this->call)('POST', '/api/v1/series', ['document_type' => '07', 'code' => 'NC01'])
        ->assertUnprocessable()->assertJsonPath('errors.code.0', 'La serie de Nota de crédito debe empezar por F (notas de facturas) o B (notas de boletas) y tener 4 caracteres (p. ej. FC01 o BC01).');
});

it('007 el Nuevo RUS no crea series de nota de crédito de factura', function () {
    $this->company->update(['tax_regime' => TaxRegime::Nrus]);

    ($this->call)('POST', '/api/v1/series', ['document_type' => '07', 'code' => 'FC01'])
        ->assertUnprocessable()->assertJsonPath('errors.document_type.0', 'Las empresas del Nuevo RUS no emiten facturas ni sus notas de crédito.');
    ($this->call)('POST', '/api/v1/series', ['document_type' => '07', 'code' => 'BC01'])->assertCreated();
});

<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentLookup;
use App\Models\DocumentLookupRequest;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * T006 · HU-1, HU-2, RF-001 a RF-005, CE-002 (spec 018): buscar al cliente
 * por DNI o RUC: primero la empresa, luego la tabla común y al final el
 * servicio.
 */

const SUNAT_RUC = '20131312955';

function rucResponse(array $overrides = []): array
{
    return ['ruc' => SUNAT_RUC, 'razonSocial' => 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT',
        'estado' => 'ACTIVO', 'condicion' => 'HABIDO', 'direccion' => 'AV. GARCILASO DE LA VEGA NRO. 1472 LIMA LIMA LIMA', 'ubigeo' => '150101', ...$overrides];
}

beforeEach(function () {
    config(['services.apisperu.token' => 'token-de-prueba', 'services.apisperu.base_url' => 'https://dniruc.test/api/v1']);
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->lookup = function (string $type, string $number, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)
            ->postJson('/api/v1/customers/lookup', ['document_type' => $type, 'document_number' => $number]);
    };
});

it('valida el formato antes de consultar, sin gastar consultas', function (string $type, string $number) {
    Http::fake();

    ($this->lookup)($type, $number)->assertUnprocessable()->assertJsonValidationErrors(['document_number']);
    Http::assertNothingSent();
})->with([
    'DNI de 7 dígitos' => ['1', '4602789'],
    'DNI con letras' => ['1', '4602789A'],
    'RUC con dígito verificador malo' => ['6', '20131312954'],
    'RUC corto' => ['6', '2013131295'],
]);

it('el carné de extranjería se registra a mano', function () {
    Http::fake();

    ($this->lookup)('4', '001234567')->assertUnprocessable()->assertJsonPath('errors.document_type.0', 'Este documento se registra a mano: solo se consultan DNI y RUC.');
    Http::assertNothingSent();
});

it('un documento nuevo se consulta en el servicio y queda como cliente verificado', function () {
    Http::fake(['*' => Http::response(rucResponse())]);

    ($this->lookup)('6', SUNAT_RUC)->assertOk()
        ->assertJsonPath('meta.source', 'service')
        ->assertJsonPath('meta.reason', null)
        ->assertJsonPath('data.name', 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT')
        ->assertJsonPath('data.address', 'AV. GARCILASO DE LA VEGA NRO. 1472 LIMA LIMA LIMA')
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.ruc_status', 'ACTIVO')
        ->assertJsonPath('data.ruc_condition', 'HABIDO');

    app(TenantContext::class)->set($this->company);
    expect(Customer::sole()->verified_at)->not->toBeNull()
        ->and(DocumentLookup::sole()->only(['document_number', 'ubigeo']))->toBe(['document_number' => SUNAT_RUC, 'ubigeo' => '150101'])
        ->and(DocumentLookupRequest::sole()->only(['company_id', 'outcome', 'document_type']))->toBe(['company_id' => $this->company->id, 'outcome' => 'found', 'document_type' => '6']);
});

it('un cliente ya guardado en la empresa aparece sin llamar al servicio', function () {
    Http::fake(['*' => Http::response(rucResponse())]);
    ($this->lookup)('6', SUNAT_RUC)->assertOk();

    ($this->lookup)('6', SUNAT_RUC)->assertOk()->assertJsonPath('meta.source', 'company');

    Http::assertSentCount(1);
});

it('CE-002: otra empresa usa la tabla común sin gastar consulta', function () {
    Http::fake(['*' => Http::response(rucResponse())]);
    ($this->lookup)('6', SUNAT_RUC)->assertOk();

    app(TenantContext::class)->clear();
    $other = Company::factory()->withMainEstablishment()->create();
    $otherSeller = User::factory()->forCompany($other)->create();

    ($this->lookup)('6', SUNAT_RUC, $otherSeller)->assertOk()
        ->assertJsonPath('meta.source', 'platform')
        ->assertJsonPath('data.verified', true);

    Http::assertSentCount(1);
    expect(Customer::withoutTenancy()->where('document_number', SUNAT_RUC)->pluck('company_id')->sort()->values()->all())->toBe([$this->company->id, $other->id])
        ->and(DocumentLookupRequest::count())->toBe(1);
});

it('un DNI queda con el nombre como «APELLIDOS NOMBRES»', function () {
    Http::fake(['*' => Http::response(['success' => true, 'dni' => '46027897', 'nombres' => 'MARIA', 'apellidoPaterno' => 'QUISPE', 'apellidoMaterno' => 'MAMANI'])]);

    ($this->lookup)('1', '46027897')->assertOk()->assertJsonPath('data.name', 'QUISPE MAMANI MARIA')->assertJsonPath('data.ruc_status', null);
});

it('HU-2 esc. 2: un cliente manual encontrado se reemplaza y pasa a verificado, con auditoría', function () {
    app(TenantContext::class)->set($this->company);
    Customer::factory()->withRuc()->create(['company_id' => $this->company->id, 'document_number' => SUNAT_RUC, 'name' => 'Sunat mal escrita', 'address' => null]);
    Http::fake(['*' => Http::response(rucResponse())]);

    ($this->lookup)('6', SUNAT_RUC)->assertOk()
        ->assertJsonPath('meta.source', 'service')
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.name', 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT');

    $log = AuditLog::withoutTenancy()->where('action', 'customer.verified')->sole();
    expect($log->changes['name'])->toBe(['from' => 'Sunat mal escrita', 'to' => 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT']);
});

it('un cliente manual que el servicio no encuentra vuelve como está, con el motivo', function () {
    app(TenantContext::class)->set($this->company);
    Customer::factory()->create(['company_id' => $this->company->id, 'document_number' => '46027897', 'name' => 'Cliente manual']);
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'No se encontraron resultados.'])]);

    ($this->lookup)('1', '46027897')->assertOk()
        ->assertJsonPath('data.name', 'Cliente manual')
        ->assertJsonPath('data.verified', false)
        ->assertJsonPath('meta.source', 'company')
        ->assertJsonPath('meta.reason', 'not_found');
});

it('HU-1 esc. 5: cada falla del servicio vuelve sin cliente y con su motivo', function (Closure $response, string $reason) {
    Http::fake(['*' => $response]);

    ($this->lookup)('6', SUNAT_RUC)->assertOk()->assertJsonPath('data', null)->assertJsonPath('meta.reason', $reason)->assertJsonPath('meta.source', null);

    app(TenantContext::class)->set($this->company);
    expect(Customer::count())->toBe(0)
        ->and(DocumentLookupRequest::sole()->outcome)->toBe($reason);
})->with([
    'no encontrado' => [fn () => Http::response(['success' => false, 'message' => 'No se encontraron resultados.']), 'not_found'],
    'servicio caído' => [fn () => Http::response('', 503), 'unavailable'],
    'sin cupo' => [fn () => Http::response([], 429), 'quota'],
]);

it('sin token solo usa lo guardado y no registra uso', function () {
    config(['services.apisperu.token' => null]);
    Http::fake();

    ($this->lookup)('6', SUNAT_RUC)->assertOk()->assertJsonPath('meta.reason', 'disabled');

    Http::assertNothingSent();
    expect(DocumentLookupRequest::count())->toBe(0);
});

it('la respuesta nunca trae el token', function () {
    Http::fake(['*' => Http::response(rucResponse())]);

    expect(($this->lookup)('6', SUNAT_RUC)->getContent())->not->toContain('token-de-prueba');
});

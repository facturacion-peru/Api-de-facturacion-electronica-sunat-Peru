<?php

use App\Enums\CompanyRole;
use App\Enums\CustomerDocumentType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentLookup;
use App\Models\DocumentLookupRequest;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * T010 y T011 · HU-3, HU-4, A-94, RF-008, RF-013 (spec 018): «Actualizar
 * datos» vuelve a consultar a pedido; la plataforma ve el uso por día.
 */

beforeEach(function () {
    config(['services.apisperu.token' => 'token-de-prueba', 'services.apisperu.base_url' => 'https://dniruc.test/api/v1']);
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    app(TenantContext::class)->set($this->company);
    $this->customer = Customer::factory()->create([
        'company_id' => $this->company->id, 'document_type' => CustomerDocumentType::Ruc, 'document_number' => '20131312955',
        'name' => 'SUNAT', 'address' => 'DIRECCIÓN VIEJA', 'verified_at' => now()->subYear(),
    ]);
    DocumentLookup::create(['document_type' => CustomerDocumentType::Ruc, 'document_number' => '20131312955', 'name' => 'SUNAT', 'address' => 'DIRECCIÓN VIEJA', 'fetched_at' => now()->subYear()]);
    $this->refresh = function (?User $as = null, ?Customer $customer = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)
            ->postJson('/api/v1/customers/'.($customer ?? $this->customer)->id.'/refresh');
    };
});

it('HU-3: «Actualizar datos» consulta aunque esté guardado y pone al día el cliente y la tabla común', function () {
    Http::fake(['*' => Http::response(['ruc' => '20131312955', 'razonSocial' => 'SUNAT', 'estado' => 'ACTIVO', 'condicion' => 'HABIDO', 'direccion' => 'AV. NUEVA 1'])]);

    ($this->refresh)()->assertOk()
        ->assertJsonPath('data.address', 'AV. NUEVA 1')
        ->assertJsonPath('data.ruc_status', 'ACTIVO');

    Http::assertSentCount(1);
    expect(DocumentLookup::sole()->address)->toBe('AV. NUEVA 1')
        ->and($this->customer->fresh()->verified_at->isToday())->toBeTrue()
        ->and(AuditLog::withoutTenancy()->where('action', 'customer.refreshed')->sole()->changes['address'])->toBe(['from' => 'DIRECCIÓN VIEJA', 'to' => 'AV. NUEVA 1']);
});

it('un cliente manual también se puede actualizar y queda verificado', function () {
    $manual = Customer::factory()->create(['company_id' => $this->company->id, 'document_number' => '46027897', 'name' => 'Manual']);
    Http::fake(['*' => Http::response(['success' => true, 'nombres' => 'ANA', 'apellidoPaterno' => 'RUIZ', 'apellidoMaterno' => 'DIAZ'])]);

    ($this->refresh)(null, $manual)->assertOk()->assertJsonPath('data.name', 'RUIZ DIAZ ANA')->assertJsonPath('data.verified', true);
});

it('si el servicio falla, el cliente no cambia y responde 503 con el motivo', function (Closure $response, string $message) {
    Http::fake(['*' => $response]);

    ($this->refresh)()->assertStatus(503)->assertJsonPath('message', $message)->assertJsonPath('meta.reason', fn ($r) => $r !== null);

    expect($this->customer->fresh()->address)->toBe('DIRECCIÓN VIEJA');
})->with([
    'caído' => [fn () => Http::response('', 502), 'El servicio de consulta no responde. Inténtalo más tarde.'],
    'sin cupo' => [fn () => Http::response([], 429), 'Se acabaron las consultas de hoy. Inténtalo mañana.'],
    'no encontrado' => [fn () => Http::response(['success' => false, 'message' => 'No se encontraron resultados.']), 'El servicio no encontró este documento.'],
]);

it('sin token, actualizar responde 503 sin llamar al servicio', function () {
    config(['services.apisperu.token' => null]);
    Http::fake();

    ($this->refresh)()->assertStatus(503)->assertJsonPath('message', 'La consulta de DNI y RUC no está activada.');
    Http::assertNothingSent();
});

it('un carné de extranjería no se actualiza', function () {
    $foreigner = Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::ForeignerCard, 'document_number' => 'X1234567']);
    Http::fake();

    ($this->refresh)(null, $foreigner)->assertUnprocessable()->assertJsonValidationErrors(['document_type']);
    Http::assertNothingSent();
});

it('un comprobante ya emitido no cambia al actualizar', function () {
    $series = Series::factory()->create(['company_id' => $this->company->id]);
    $document = SalesDocument::factory()->create(['series_id' => $series->id, 'customer_id' => $this->customer->id, 'customer_name' => 'SUNAT', 'customer_address' => 'DIRECCIÓN VIEJA']);
    Http::fake(['*' => Http::response(['ruc' => '20131312955', 'razonSocial' => 'SUNAT NUEVA', 'direccion' => 'AV. NUEVA 1'])]);

    ($this->refresh)()->assertOk();

    expect($document->fresh()->only(['customer_name', 'customer_address']))->toBe(['customer_name' => 'SUNAT', 'customer_address' => 'DIRECCIÓN VIEJA']);
});

it('HU-4: la plataforma ve las consultas por día, sin números de documento', function () {
    Carbon::setTestNow('2026-10-30 10:00:00');
    foreach (['found', 'found', 'not_found', 'unavailable'] as $outcome) {
        DocumentLookupRequest::create(['company_id' => $this->company->id, 'document_type' => '6', 'outcome' => $outcome]);
    }
    DocumentLookupRequest::create(['company_id' => $this->company->id, 'document_type' => '1', 'outcome' => 'quota'])->forceFill(['created_at' => now()->subDays(2)])->save();
    DocumentLookupRequest::create(['company_id' => $this->company->id, 'document_type' => '1', 'outcome' => 'found'])->forceFill(['created_at' => now()->subDays(20)])->save();
    $platform = User::factory()->platformAdmin()->create();
    app(TenantContext::class)->clear();

    $response = $this->withToken($platform->createToken('t')->plainTextToken)->getJson('/api/v1/platform/document-lookups/usage')->assertOk();
    $days = collect($response->json('data'))->keyBy('date');

    expect($response->json('data'))->toHaveCount(14)
        ->and($days['2026-10-30'])->toBe(['date' => '2026-10-30', 'total' => 4, 'found' => 2, 'not_found' => 1, 'failed' => 1])
        ->and($days['2026-10-28'])->toBe(['date' => '2026-10-28', 'total' => 1, 'found' => 0, 'not_found' => 0, 'failed' => 1])
        ->and($days['2026-10-29']['total'])->toBe(0)
        ->and($response->getContent())->not->toContain('20131312955');

    Carbon::setTestNow();
});

it('la empresa no ve el uso de la plataforma', function () {
    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)->getJson('/api/v1/platform/document-lookups/usage')->assertForbidden();
});

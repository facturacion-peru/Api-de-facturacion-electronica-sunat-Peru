<?php

use App\Enums\CompanyRole;
use App\Enums\CustomerDocumentType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\SpreadsheetFile;

/*
 * Spec 014 · HU-2: exportar clientes (T011).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($this->company);

    Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::Dni, 'document_number' => '01234567', 'name' => 'Ana Pérez', 'address' => null]);
    Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::Ruc, 'document_number' => '20131312955', 'name' => 'Bodega Ñaña S.A.C.', 'address' => '@Jr. Lima 123']);
    Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::ForeignerCard, 'document_number' => 'X12345678', 'name' => 'John Smith', 'address' => 'Av. Arequipa 1']);

    $this->export = function (array $query = []) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->get('/api/v1/exports/customers?'.http_build_query($query));
    };
});

afterEach(fn () => Carbon::setTestNow());

it('HU-2.1 exporta una fila por cliente con tipo, número, nombre y dirección', function (string $format) {
    $response = ($this->export)(['format' => $format]);

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain("clientes-2026-10-07.{$format}");
    $sheets = SpreadsheetFile::sheets($response);
    $rows = collect($sheets['clientes'] ?? $sheets['clientes-2026-10-07'])->keyBy('numero_documento');

    expect($rows->keys()->map(fn ($key) => (string) $key)->sort()->values()->all())->toBe(['01234567', '20131312955', 'X12345678'])
        ->and($rows['01234567'])->toBe(['tipo_documento' => 'DNI', 'numero_documento' => '01234567', 'nombre' => 'Ana Pérez', 'direccion' => ''])
        ->and($rows['20131312955'])->toBe(['tipo_documento' => 'RUC', 'numero_documento' => '20131312955', 'nombre' => 'Bodega Ñaña S.A.C.', 'direccion' => '@Jr. Lima 123'])
        ->and($rows['X12345678']['tipo_documento'])->toBe('CE');
})->with(['xlsx', 'csv']);

it('respeta la búsqueda del listado', function () {
    $rows = SpreadsheetFile::sheets(($this->export)(['search' => 'bodega']))['clientes'];

    expect(array_column($rows, 'numero_documento'))->toBe(['20131312955']);
});

it('HU-2.2 deja la exportación en la auditoría', function () {
    ($this->export)()->assertOk();

    $log = AuditLog::query()->where('action', 'export.customers')->sole();
    expect($log->actor_id)->toBe($this->admin->id)
        ->and($log->changes)->toMatchArray(['format' => 'xlsx', 'rows' => 3, 'filters' => ['search' => null]]);
});

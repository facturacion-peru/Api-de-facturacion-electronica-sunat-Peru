<?php

use App\Enums\CompanyRole;
use App\Enums\CustomerDocumentType;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\ImportFiles;

/*
 * Spec 014 · HU-5 (T022): importar clientes con las validaciones del alta
 * manual (DNI, CE y RUC con dígito verificador).
 */

const CUSTOMER_HEADERS = ['tipo_documento', 'numero_documento', 'nombre', 'direccion'];

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($this->company);

    $this->ana = Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::Dni, 'document_number' => '01234567', 'name' => 'Ana Pérez', 'address' => null]);

    $this->call = function (string $method, string $uri, array $data = []) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)->json($method, $uri, $data);
    };
    $this->preview = fn (array $rows, string $mode = 'create', array $headers = CUSTOMER_HEADERS) => ($this->call)('POST', '/api/v1/imports/customers/preview', [
        'file' => ImportFiles::make($headers, $rows, name: 'clientes'), 'mode' => $mode,
    ]);
});

it('HU-5.1 crea clientes con DNI, CE y RUC válidos, aceptando la sigla o el código SUNAT', function () {
    $preview = ($this->preview)([
        ['DNI', '45678912', 'Luis Quispe', ''],
        ['ce', 'x12345678', 'John Smith', 'Av. Arequipa 1'],
        ['6', '20131312955', 'Bodega Ñaña S.A.C.', 'Jr. Lima 123'],
    ]);
    $preview->assertCreated()->assertJsonPath('data.summary', ['rows' => 3, 'create' => 3, 'update' => 0, 'unchanged' => 0, 'errors' => 0])
        ->assertJsonPath('data.changes.1', ['row' => 3, 'action' => 'create', 'key' => 'CE X12345678', 'name' => 'John Smith']);

    ($this->call)('POST', '/api/v1/imports/'.$preview->json('data.id').'/confirm')->assertOk()
        ->assertJsonPath('data', ['created' => 3, 'updated' => 0, 'entries' => 0]);

    expect(Customer::query()->where('document_number', 'X12345678')->sole()->document_type)->toBe(CustomerDocumentType::ForeignerCard)
        ->and(Customer::query()->where('document_number', '20131312955')->sole()->created_by)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'customer.created')->count())->toBe(3);
});

it('HU-5.1 valida como el alta manual, con el nombre de la columna', function () {
    $response = ($this->preview)([
        ['DNI', '1234567', 'Siete dígitos', ''],
        ['RUC', '20131312956', 'Dígito verificador mal', ''],
        ['PASAPORTE', '123', 'Tipo desconocido', ''],
        ['DNI', '87654321', '', ''],
        ['DNI', '01234567', 'Ana Pérez', ''],
    ]);

    expect(collect($response->json('data.errors'))->map(fn ($e) => "{$e['row']}:{$e['column']}")->all())
        ->toBe(['2:numero_documento', '3:numero_documento', '4:tipo_documento', '5:nombre', '6:numero_documento'])
        ->and($response->json('data.errors.0.message'))->toBe('El DNI debe tener 8 dígitos. Revisa que la columna esté como texto en Excel: se pierden los ceros a la izquierda.')
        ->and($response->json('data.errors.1.message'))->toBe('El RUC no es válido: el dígito verificador no coincide.')
        ->and($response->json('data.errors.4.message'))->toBe('Ya existe un cliente con ese documento.');
});

it('marca como error las filas de un documento repetido en el archivo', function () {
    $response = ($this->preview)([['DNI', '45678912', 'Luis', ''], ['DNI', '45678912', 'Luis Q.', '']]);

    expect($response->json('data.errors'))->toBe([
        ['row' => 2, 'column' => 'numero_documento', 'message' => 'El documento DNI 45678912 se repite en las filas 2 y 3.'],
        ['row' => 3, 'column' => 'numero_documento', 'message' => 'El documento DNI 45678912 se repite en las filas 2 y 3.'],
    ]);
});

it('HU-5.2 «crear y actualizar» cambia solo nombre y dirección, con ceros a la izquierda conservados', function () {
    $preview = ($this->preview)([['DNI', '01234567', 'Ana María Pérez', 'Calle 1']], 'upsert');

    $preview->assertCreated()->assertJsonPath('data.changes', [[
        'row' => 2, 'action' => 'update', 'key' => 'DNI 01234567', 'name' => 'Ana Pérez',
        'fields' => ['nombre' => ['from' => 'Ana Pérez', 'to' => 'Ana María Pérez'], 'direccion' => ['from' => null, 'to' => 'Calle 1']],
    ]]);

    ($this->call)('POST', '/api/v1/imports/'.$preview->json('data.id').'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
    expect($this->ana->fresh()->only('name', 'address', 'document_number'))->toBe(['name' => 'Ana María Pérez', 'address' => 'Calle 1', 'document_number' => '01234567']);
});

it('falta una columna obligatoria', function () {
    ($this->preview)([['DNI', 'Luis']], 'create', ['tipo_documento', 'nombre'])
        ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Falta la columna «numero_documento». Usa la plantilla.');
});

it('409 si un documento se registró después de la vista previa: no se crea ninguno', function () {
    $preview = ($this->preview)([['DNI', '45678912', 'Luis Quispe', ''], ['DNI', '11223344', 'Rosa Díaz', '']]);
    Customer::factory()->create(['company_id' => $this->company->id, 'document_type' => CustomerDocumentType::Dni, 'document_number' => '11223344', 'name' => 'Rosa']);

    ($this->call)('POST', '/api/v1/imports/'.$preview->json('data.id').'/confirm')->assertConflict()
        ->assertJsonPath('message', 'Los clientes cambiaron desde la vista previa: vuelve a subir el archivo para revisarla de nuevo.');
    expect(Customer::query()->where('document_number', '45678912')->exists())->toBeFalse();
});

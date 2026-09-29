<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
 * T020 · HU-5 Clientes (RF-030, RF-031, A-34).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();

    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken);
    };
});

it('HU-5.1 busca por documento o nombre, solo en la propia empresa', function () {
    Customer::factory()->create(['company_id' => $this->company->id, 'document_number' => '46027897', 'name' => 'MARIA QUISPE']);
    Customer::factory()->withRuc()->create(['company_id' => $this->company->id, 'name' => 'FERRETERIA EL SOL S.A.C.']);
    $other = Company::factory()->create();
    Customer::factory()->create(['company_id' => $other->id, 'name' => 'MARIA DE OTRA EMPRESA']);

    $byName = ($this->as)($this->seller)->getJson('/api/v1/customers?search=maria')->assertOk()->json('data');
    $byDoc = ($this->as)($this->seller)->getJson('/api/v1/customers?search=4602')->assertOk()->json('data');

    expect(array_column($byName, 'name'))->toBe(['MARIA QUISPE'])
        ->and(array_column($byDoc, 'document_number'))->toBe(['46027897'])
        ->and($byName[0])->toHaveKeys(['id', 'document_type', 'document_type_label', 'document_number', 'name', 'address']);
});

it('HU-5.2 el vendedor registra un cliente con DNI y se audita', function () {
    $response = ($this->as)($this->seller)->postJson('/api/v1/customers', [
        'document_type' => '1', 'document_number' => '46027897', 'name' => 'María Quispe',
    ]);

    $response->assertCreated()->assertJsonPath('data.document_type_label', 'DNI')->assertJsonPath('data.name', 'María Quispe');
    expect(AuditLog::withoutTenancy()->where('action', 'customer.created')->count())->toBe(1);
});

it('HU-5.2 registra una empresa con RUC válido', function () {
    ($this->as)($this->seller)->postJson('/api/v1/customers', [
        'document_type' => '6', 'document_number' => '20131312955', 'name' => 'Cliente S.A.C.', 'address' => 'Av. Uno 123',
    ])->assertCreated()->assertJsonPath('data.address', 'Av. Uno 123');
});

it('HU-5.2 valida el formato del documento', function (string $type, string $number, string $message) {
    ($this->as)($this->seller)->postJson('/api/v1/customers', ['document_type' => $type, 'document_number' => $number, 'name' => 'X'])
        ->assertStatus(422)->assertJsonPath('errors.document_number.0', $message);
})->with([
    'DNI corto' => ['1', '4602789', 'El DNI debe tener 8 dígitos.'],
    'RUC con dígito verificador errado' => ['6', '20131312954', 'El RUC no es válido: el dígito verificador no coincide.'],
    'carné corto' => ['4', '12345', 'El carné de extranjería debe tener de 8 a 12 letras o números.'],
]);

it('HU-5.2 rechaza un documento ya registrado en la empresa', function () {
    Customer::factory()->create(['company_id' => $this->company->id, 'document_number' => '46027897']);

    ($this->as)($this->seller)->postJson('/api/v1/customers', ['document_type' => '1', 'document_number' => '46027897', 'name' => 'X'])
        ->assertStatus(422)->assertJsonPath('errors.document_number.0', 'Ya existe un cliente con ese documento.');
});

it('el mismo documento puede existir en otra empresa', function () {
    Customer::factory()->create(['company_id' => Company::factory()->create()->id, 'document_number' => '46027897']);
    app(TenantContext::class)->clear();

    ($this->as)($this->seller)->postJson('/api/v1/customers', ['document_type' => '1', 'document_number' => '46027897', 'name' => 'X'])
        ->assertCreated();
});

it('HU-5.3 solo el administrador edita, y se audita el cambio', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'MARIA']);

    ($this->as)($this->seller)->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'OTRO'])->assertForbidden();
    ($this->as)($this->admin)->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'MARIA QUISPE', 'address' => 'Jr. Dos 45'])
        ->assertOk()->assertJsonPath('data.name', 'MARIA QUISPE');

    $log = AuditLog::withoutTenancy()->where('action', 'customer.updated')->sole();
    expect($log->changes['name'])->toBe(['from' => 'MARIA', 'to' => 'MARIA QUISPE']);
});

it('HU-5.3 editar no cambia el documento a uno repetido', function () {
    Customer::factory()->create(['company_id' => $this->company->id, 'document_number' => '11111111']);
    $customer = Customer::factory()->create(['company_id' => $this->company->id, 'document_number' => '22222222']);

    ($this->as)($this->admin)->patchJson("/api/v1/customers/{$customer->id}", ['document_type' => '1', 'document_number' => '11111111'])
        ->assertStatus(422);
});

it('no existe ruta para borrar clientes', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);

    ($this->as)($this->admin)->deleteJson("/api/v1/customers/{$customer->id}")->assertStatus(405);
});

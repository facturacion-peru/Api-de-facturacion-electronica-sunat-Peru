<?php

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invitation;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\SunatSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * T090 · HU-4.1 a HU-4.3: un usuario de la empresa A nunca lee ni modifica
 * datos de la empresa B (RF-030 a RF-033, CE-002). Recorre el inventario de
 * TenantRoutes.php, que TenantRoutesCoverageTest mantiene completo.
 */

function tenantRoutes(string $type): array
{
    return collect(require __DIR__.'/TenantRoutes.php')
        ->filter(fn (array $case) => $case['type'] === $type)
        ->map(fn (array $case, string $route) => [$route, $case])
        ->all();
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('public');

    $this->a = Company::factory()->withMainEstablishment()->create(['razon_social' => 'Empresa A S.A.C.']);
    $this->b = Company::factory()->withMainEstablishment()->create(['razon_social' => 'Empresa B S.A.C.', 'nombre_comercial' => 'Marca B']);

    $this->adminA = User::factory()->forCompany($this->a, CompanyRole::CompanyAdmin)->create();
    $this->adminB = User::factory()->forCompany($this->b, CompanyRole::CompanyAdmin)->create(['email' => 'admin-b@empresa-b.pe']);
    $this->sellerB = User::factory()->forCompany($this->b, CompanyRole::Seller)->create(['email' => 'vendedor-b@empresa-b.pe']);
    $this->invitationB = Invitation::factory()->create(['company_id' => $this->b->id, 'email' => 'invitado-b@empresa-b.pe']);

    app(TenantContext::class)->run($this->b, fn () => app(AuditLogger::class)->record('b.accion_secreta', $this->sellerB, actor: $this->adminB));

    $this->productB = Product::factory()->create(['company_id' => $this->b->id, 'code' => 'B-SECRETO', 'name' => 'Producto secreto de B', 'min_stock' => '100']);
    $this->lotB = ProductLot::factory()->for($this->productB)->create(['lot_number' => 'LOTE-DE-B']);
    $this->ticketB = Ticket::factory()->create(['company_id' => $this->b->id, 'customer_name' => 'Cliente secreto de B']);
    $this->seriesB = Series::factory()->create(['company_id' => $this->b->id, 'code' => 'BZ99', 'last_number' => 42]);
    $this->customerB = Customer::factory()->create(['company_id' => $this->b->id, 'document_number' => '87654321', 'name' => 'Comprador secreto de B']);
    $this->documentB = SalesDocument::factory()->status(App\Enums\SalesDocumentStatus::Pending)->create(['series_id' => $this->seriesB->id, 'customer_name' => 'Comprador secreto de B']);
    $this->sunatB = SunatSetting::create(['company_id' => $this->b->id, 'environment' => 'beta', 'status' => 'pending', 'sol_user' => 'USUARIOB', 'sol_password' => 'ClaveSolDeB']);

    $this->token = $this->adminA->createToken('t')->plainTextToken;

    /** Rastros de B que nunca deben aparecer en una respuesta a A. */
    $this->bMarkers = [$this->b->ruc, 'Empresa B S.A.C.', 'Marca B', 'empresa-b.pe', 'b.accion_secreta', 'B-SECRETO', 'Producto secreto de B', 'LOTE-DE-B', 'Cliente secreto de B', 'USUARIOB', 'ClaveSolDeB', 'BZ99', 'Comprador secreto de B', '87654321'];
});

function callAsA(string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->withToken(test()->token)->json($method, $uri, $data);
}

function expectNoTraceOfB($response): void
{
    // Las exportaciones son descargas en streaming (spec 014).
    $content = $response->baseResponse instanceof Symfony\Component\HttpFoundation\StreamedResponse
        ? $response->streamedContent()
        : $response->getContent();

    foreach (test()->bMarkers as $marker) {
        expect($content)->not->toContain($marker);
    }
}

it('HU-4.1 un recurso de B responde igual que uno inexistente', function (string $route, array $case) {
    [$method, $uri] = explode(' ', $route, 2);
    $idOfB = match ($case['param']) {
        'user' => $this->sellerB->id,
        'invitation' => $this->invitationB->id,
        'product' => $this->productB->id,
        'lot' => $this->lotB->id,
        'movement' => $this->lotB->movements()->withoutGlobalScopes()->value('id'),
        'ticket' => $this->ticketB->id,
        'series' => $this->seriesB->id,
        'customer' => $this->customerB->id,
        'salesDocument' => $this->documentB->id,
    };
    $payload = $method === 'PATCH'
        ? ['active' => false, 'role' => 'seller', 'name' => 'Hackeado']
        : ['quantity' => '-1', 'reason' => 'error'];

    $ofB = callAsA($method, '/'.str_replace('{'.$case['param'].'}', (string) $idOfB, $uri), $payload);
    $missing = callAsA($method, '/'.str_replace('{'.$case['param'].'}', '999999', $uri), $payload);

    $ofB->assertNotFound();
    expect($ofB->json())->toBe($missing->json());
    expectNoTraceOfB($ofB);

    expect($this->productB->fresh()->name)->toBe('Producto secreto de B')
        ->and($this->productB->fresh()->active)->toBeTrue()
        ->and(ProductLot::withoutTenancy()->where('product_id', $this->productB->id)->count())->toBe(1)
        ->and($this->lotB->fresh()->remaining_quantity)->toBe($this->lotB->initial_quantity)
        ->and($this->ticketB->fresh()->status->value)->toBe('issued')
        ->and($this->seriesB->fresh()->active)->toBeTrue()
        ->and($this->customerB->fresh()->name)->toBe('Comprador secreto de B')
        ->and($this->documentB->fresh()->attempts)->toBe(0)
        ->and($this->documentB->fresh()->status->value)->toBe('pending')
        ->and(SalesDocument::withoutTenancy()->where('reference_document_id', $this->documentB->id)->count())->toBe(0);

    expect($this->sellerB->membership()->first()->active)->toBeTrue()
        ->and(Invitation::withoutTenancy()->find($this->invitationB->id))->not->toBeNull();
})->with(tenantRoutes('resource'));

it('HU-4.2 un listado solo trae datos de A aunque se pidan los de B', function (string $route, array $case) {
    [$method, $uri] = explode(' ', $route, 2);
    $query = $case['query'] ?? [];
    // Las exportaciones de ventas piden un rango: el de hoy, donde están las ventas de B.
    if (($query['today'] ?? false) === true) {
        unset($query['today']);
        $query = [...$query, 'from' => today()->toDateString(), 'to' => today()->toDateString()];
    }

    $response = callAsA($method, '/'.$uri, [...$query, 'company_id' => $this->b->id, 'actor_id' => $this->adminB->id]);

    $response->assertOk();
    expectNoTraceOfB($response);
})->with(tenantRoutes('list'));

it('los datos propios son siempre los de A', function (string $route) {
    [$method, $uri] = explode(' ', $route, 2);

    $response = callAsA($method, '/'.$uri, ['company_id' => $this->b->id]);

    $response->assertOk();
    expect($response->json('data.company.id') ?? $response->json('data.company_id') ?? $response->json('data.id'))->toBe($this->a->id);
    expectNoTraceOfB($response);
})->with(tenantRoutes('own'));

it('HU-4.3 crear o modificar apuntando a B no afecta a B', function (string $route) {
    $bBefore = $this->b->fresh()->toArray();

    $response = match ($route) {
        'PATCH api/v1/company' => callAsA('PATCH', '/api/v1/company', ['company_id' => $this->b->id, 'id' => $this->b->id, 'nombre_comercial' => 'Hackeada']),
        'POST api/v1/company/logo' => callAsA('POST', '/api/v1/company/logo', ['company_id' => $this->b->id, 'logo' => UploadedFile::fake()->image('logo.png')]),
        'POST api/v1/invitations' => callAsA('POST', '/api/v1/invitations', ['company_id' => $this->b->id, 'email' => 'nuevo@example.com', 'role' => 'seller']),
        'POST api/v1/tickets' => callAsA('POST', '/api/v1/tickets', [
            'company_id' => $this->b->id, 'idempotency_key' => $this->ticketB->idempotency_key, 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->productB->id, 'quantity' => '1']],
        ]),
        'PUT api/v1/sunat/credentials' => callAsA('PUT', '/api/v1/sunat/credentials', ['company_id' => $this->b->id, 'sol_user' => 'NUEVOA', 'sol_password' => 'otra']),
        'POST api/v1/sunat/certificate' => callAsA('POST', '/api/v1/sunat/certificate', ['company_id' => $this->b->id, 'password' => 'x']),
        'POST api/v1/sunat/validate' => callAsA('POST', '/api/v1/sunat/validate', ['company_id' => $this->b->id]),
        'POST api/v1/customers' => callAsA('POST', '/api/v1/customers', ['company_id' => $this->b->id, 'document_type' => '1', 'document_number' => '11223344', 'name' => 'Cliente de A']),
        'POST api/v1/sales-documents' => callAsA('POST', '/api/v1/sales-documents', [
            'company_id' => $this->b->id, 'idempotency_key' => $this->ticketB->idempotency_key, 'document_type' => '03',
            'series_id' => $this->seriesB->id, 'customer_id' => $this->customerB->id, 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->productB->id, 'quantity' => '1']],
        ]),
        'POST api/v1/series' => callAsA('POST', '/api/v1/series', ['company_id' => $this->b->id, 'document_type' => '03', 'code' => 'B777']),
        'POST api/v1/products' => callAsA('POST', '/api/v1/products', [
            'company_id' => $this->b->id, 'code' => 'A-NUEVO', 'name' => 'Nuevo', 'type' => 'good', 'unit' => 'NIU', 'sale_price' => '1', 'igv_affectation' => '10',
        ]),
    };

    expect($response->status())->toBeLessThan(500);
    expect($this->b->fresh()->toArray())->toBe($bBefore);
    expect(Invitation::withoutTenancy()->where('company_id', $this->b->id)->pluck('email')->all())->toBe(['invitado-b@empresa-b.pe'])
        ->and(Product::withoutTenancy()->where('company_id', $this->b->id)->pluck('name')->all())->toBe(['Producto secreto de B'])
        ->and($this->sunatB->fresh()->sol_password)->toBe('ClaveSolDeB')
        ->and($this->sunatB->fresh()->status->value)->toBe('pending')
        ->and(Series::withoutTenancy()->where('company_id', $this->b->id)->pluck('code')->all())->toBe(['BZ99'])
        ->and(Customer::withoutTenancy()->where('company_id', $this->b->id)->pluck('name')->all())->toBe(['Comprador secreto de B'])
        ->and(SalesDocument::withoutTenancy()->pluck('id')->all())->toBe([$this->documentB->id])
        ->and($this->seriesB->fresh()->last_number)->toBe(42);
    expectNoTraceOfB($response);
})->with(array_keys(tenantRoutes('write')));

it('las rutas de referencia exentas justifican su exención', function (string $route, array $case) {
    expect($case['reason'] ?? '')->not->toBeEmpty();
})->with(tenantRoutes('reference'));

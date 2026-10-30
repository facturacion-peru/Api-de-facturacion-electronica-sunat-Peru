<?php

use App\Enums\SalesDocumentStatus;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * T023 · CE-002, A-87 (spec 017): un vendedor del local A nunca ve tickets,
 * comprobantes, series, lotes ni movimientos del local B, y el detalle de una
 * venta de B no existe para él. Recorre el inventario de TenantRoutes.php,
 * que TenantRoutesCoverageTest mantiene completo, como CrossTenantAccessTest.
 */

function establishmentRoutes(array $types): array
{
    return collect(require __DIR__.'/../Tenancy/TenantRoutes.php')
        ->filter(fn (array $case) => in_array($case['type'], $types, true))
        ->map(fn (array $case, string $route) => [$route, $case])
        ->all();
}

beforeEach(function () {
    Storage::fake('public');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->b = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->b->update(['name' => 'Local B reservado', 'address' => 'Calle del local B 9']);
    $this->a = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001', 'name' => 'Local A']);
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->seller->establishments()->sync([$this->a->id => ['company_id' => $this->company->id]]);

    // El catálogo es de la empresa; lo que pasa en B es de B.
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'min_stock' => '1000']);
    $this->lotB = ProductLot::factory()->for($this->product)->quantity('7')->create(['establishment_id' => $this->b->id, 'lot_number' => 'LOTE-DEL-LOCAL-B']);
    ProductLot::factory()->for($this->product)->quantity('2')->create(['establishment_id' => $this->a->id, 'lot_number' => 'LOTE-A']);
    $this->ticketB = Ticket::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->b->id, 'seller_id' => $this->seller->id, 'customer_name' => 'Cliente del local B']);
    $this->seriesB = Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->b->id, 'code' => 'BZ77']);
    $this->documentB = SalesDocument::factory()->status(SalesDocumentStatus::Pending)
        ->create(['series_id' => $this->seriesB->id, 'seller_id' => $this->seller->id, 'customer_name' => 'Comprador del local B']);

    $this->token = $this->seller->createToken('t')->plainTextToken;
    // La dirección de B no es un marcador: B es el principal y su dirección es
    // el domicilio fiscal de la empresa, que todos ven en «Mi empresa».
    $this->bMarkers = ['LOTE-DEL-LOCAL-B', 'Cliente del local B', 'Comprador del local B', 'BZ77', 'Local B reservado'];
});

function callAsSellerOfA(string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->withToken(test()->token)->json($method, $uri, $data);
}

function expectNoTraceOfLocalB($response): void
{
    $content = $response->baseResponse instanceof Symfony\Component\HttpFoundation\StreamedResponse
        ? $response->streamedContent()
        : $response->getContent();

    foreach (test()->bMarkers as $marker) {
        expect($content)->not->toContain($marker);
    }
}

it('ninguna respuesta de lectura trae datos del local B', function (string $route, array $case) {
    [$method, $uri] = explode(' ', $route, 2);
    if ($method !== 'GET') {
        expect(true)->toBeTrue();

        return;
    }

    $ids = [
        'product' => $this->product->id, 'ticket' => $this->ticketB->id, 'salesDocument' => $this->documentB->id,
        'series' => $this->seriesB->id, 'lot' => $this->lotB->id, 'kind' => 'products',
    ];
    $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($ids[$m[1]] ?? 999999), $uri);
    $query = $case['query'] ?? [];
    unset($query['today']);

    $response = callAsSellerOfA('GET', '/'.$uri, $query);

    expect($response->status())->toBeLessThan(500);
    expectNoTraceOfLocalB($response);
})->with(establishmentRoutes(['list', 'own', 'resource', 'reference']));

it('las ventas del local B no existen para el vendedor del local A', function (string $route, array $case) {
    [$method, $uri] = explode(' ', $route, 2);
    $id = $case['param'] === 'ticket' ? $this->ticketB->id : $this->documentB->id;

    $response = callAsSellerOfA($method, '/'.str_replace('{'.$case['param'].'}', (string) $id, $uri), ['reason' => 'x', 'reason_code' => '01', 'idempotency_key' => (string) Str::uuid()]);

    // 404 si el vendedor tiene la ruta; 403 si es solo del administrador.
    expect($response->status())->toBeIn([403, 404]);
    expectNoTraceOfLocalB($response);
    expect($this->ticketB->fresh()->status->value)->toBe('issued')
        ->and(SalesDocument::withoutTenancy()->where('reference_document_id', $this->documentB->id)->count())->toBe(0);
})->with(collect(establishmentRoutes(['resource']))->filter(fn ($row) => in_array($row[1]['param'], ['ticket', 'salesDocument'], true))->all());

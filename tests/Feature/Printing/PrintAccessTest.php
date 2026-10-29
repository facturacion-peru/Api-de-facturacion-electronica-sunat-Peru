<?php

use App\Enums\CompanyRole;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\EscposText;
use Tests\Support\SalesFixture;

/*
 * Spec 015 · T008: acceso a las rutas de impresión. Mismas reglas que los PDF
 * (el vendedor solo imprime sus tickets, A-30) y validación de las opciones.
 * El aislamiento entre empresas lo cubre CrossTenantAccessTest (TenantRoutes.php).
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller] = SalesFixture::issuable();
    $this->company->update(['nombre_comercial' => 'Bodega Impresa']);
    $this->other = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    app(TenantContext::class)->set($this->company);
    $product = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Arroz extra 5 kg', 'sale_price' => '25.90', 'igv_affectation' => '10']);
    ProductLot::factory()->for($product)->quantity('10')->create();

    $this->ticketId = $this->withToken($this->seller->createToken('t')->plainTextToken)->postJson('/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ])->assertCreated()->json('data.id');

    app()->instance(SunatSender::class, new FakeSunatSender);
    [$this->document] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '2']],
    ], $this->seller);

    $this->print = function (string $path, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)->get('/api/v1/'.$path);
    };
});

it('devuelve los bytes ESC/POS de cada documento', function (string $path) {
    $path = str_replace(['{ticket}', '{document}'], [$this->ticketId, $this->document->id], $path);
    $response = ($this->print)($path);

    $response->assertOk()->assertHeader('content-type', 'application/octet-stream');
    expect(substr($response->getContent(), 0, 5))->toBe("\x1B@\x1Bt\x02")
        ->and(EscposText::decode($response->getContent()))->toEndWith('[CUT]');
})->with([
    'ticket' => 'tickets/{ticket}/print?width=58',
    'comprobante' => 'sales-documents/{document}/print?width=58&qr=image',
    'hoja de prueba' => 'printing/test-page?width=58',
]);

it('por defecto imprime en 80 mm con el QR nativo', function () {
    $bytes = ($this->print)('printing/test-page')->assertOk()->getContent();

    expect(EscposText::lines($bytes))->toContain(substr(str_repeat('1234567890', 5), 0, 48))
        ->and(EscposText::qrs($bytes))->toBe(['Prueba de impresora']);
});

it('la hoja de prueba lleva el nombre de la empresa, tildes y el modo de QR elegido', function () {
    $bytes = ($this->print)('printing/test-page?width=58&qr=image')->assertOk()->getContent();
    $text = implode("\n", EscposText::lines($bytes));

    expect($text)->toContain('Bodega Impresa')
        ->toContain('58 mm')
        ->toContain(substr(str_repeat('1234567890', 4), 0, 32))
        ->toContain('Tildes: áéíóú ÁÉÍÓÚ ñÑ ¿¡ «»')
        ->toContain('S/ 1,234.50')
        ->and(EscposText::decode($bytes))->toContain('[IMG ');
});

it('rechaza un ancho o un modo de QR desconocidos', function (string $query) {
    foreach (["tickets/{$this->ticketId}/print", "sales-documents/{$this->document->id}/print", 'printing/test-page'] as $path) {
        app('auth')->forgetGuards();
        $this->withToken($this->seller->createToken('t')->plainTextToken)->getJson("/api/v1/{$path}?{$query}")->assertStatus(422);
    }
})->with(['width=70', 'qr=x', 'width=abc']);

it('el administrador imprime cualquier ticket de la empresa', function () {
    ($this->print)("tickets/{$this->ticketId}/print", $this->admin)->assertOk();
});

it('el vendedor no imprime tickets ajenos (A-30): 404 como inexistente', function () {
    ($this->print)("tickets/{$this->ticketId}/print", $this->other)->assertNotFound();
});

it('otro vendedor de la empresa imprime el comprobante, como su PDF', function () {
    ($this->print)("sales-documents/{$this->document->id}/print", $this->other)->assertOk();
});

it('sin sesión responde 401', function () {
    app('auth')->forgetGuards();
    $this->withoutToken()->getJson("/api/v1/tickets/{$this->ticketId}/print")->assertUnauthorized();
    $this->getJson('/api/v1/printing/test-page')->assertUnauthorized();
});

it('el administrador de la plataforma no tiene empresa: no imprime', function () {
    $platform = User::factory()->platformAdmin()->create();
    app('auth')->forgetGuards();
    $this->withToken($platform->createToken('t')->plainTextToken)->getJson('/api/v1/printing/test-page')->assertForbidden();
});

it('RF-004: imprimir no cambia la venta, el stock ni la auditoría, y se puede repetir', function () {
    $snapshot = fn () => [
        App\Models\Ticket::find($this->ticketId)->updated_at->toJSON(),
        App\Models\SalesDocument::find($this->document->id)->updated_at->toJSON(),
        App\Models\SalesDocument::find($this->document->id)->status,
        App\Models\ProductLot::sum('remaining_quantity'),
        App\Models\InventoryMovement::count(),
        App\Models\AuditLog::withoutTenancy()->count(),
    ];
    $before = $snapshot();
    $this->travel(5)->minutes();

    $first = ($this->print)("tickets/{$this->ticketId}/print")->assertOk()->getContent();
    $second = ($this->print)("tickets/{$this->ticketId}/print")->assertOk()->getContent();
    ($this->print)("sales-documents/{$this->document->id}/print")->assertOk();
    ($this->print)('printing/test-page')->assertOk();

    expect($second)->toBe($first)->and($snapshot())->toEqual($before);
});

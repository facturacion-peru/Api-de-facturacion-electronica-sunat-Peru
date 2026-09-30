<?php

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SunatSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;
use Tests\Support\TestCertificates;

/*
 * T050 · CE-002 y A-37: ninguna respuesta del panel de la plataforma lleva
 * secretos ni datos de negocio de las empresas (productos, clientes, líneas
 * ni importes). Recorre todas las rutas GET de plataforma del router.
 */

it('el panel no expone secretos ni datos de negocio', function () {
    ['company' => $company, 'seller' => $seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($company);

    $pem = TestCertificates::signingPem();
    preg_match('/PRIVATE KEY-----\s+(\S{40})/', $pem, $key);
    SunatSetting::where('company_id', $company->id)->update(['sol_password' => encrypt('Sol-Secreta-Plataforma-1', false)]);
    Certificate::where('company_id', $company->id)->update(['password' => encrypt('Cert-Pass-Plataforma-2', false)]);

    $product = Product::factory()->create(['company_id' => $company->id, 'name' => 'Queso andino secreto', 'sale_price' => '37.91']);
    ProductLot::factory()->for($product)->quantity('10')->create();
    $customer = Customer::factory()->create(['company_id' => $company->id, 'document_number' => '48151623', 'name' => 'CLIENTA CONFIDENCIAL']);
    Ticket::factory()->create(['company_id' => $company->id, 'customer_name' => 'Comprador del ticket reservado']);
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable()));
    app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'customer_id' => $customer->id, 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ], $seller);
    app(TenantContext::class)->clear();

    $root = User::factory()->platformAdmin()->create();
    $token = $root->createToken('t')->plainTextToken;
    // Una acción de la plataforma para que la auditoría tenga contenido.
    $this->withToken($token)->postJson("/api/v1/platform/companies/{$company->id}/deactivate", ['reason' => 'Revisión'])->assertOk();

    $bodies = [];
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/platform/') && in_array('GET', $r->methods(), true));
    foreach ($routes as $route) {
        $uri = '/'.str_replace('{company}', (string) $company->id, $route->uri());
        app('auth')->forgetGuards();
        $response = $this->withToken($token)->getJson($uri.(str_contains($uri, 'ubigeos') ? '?q=Lima' : ''));
        expect($response->status())->toBe(200, $uri);
        $bodies[$uri] = $response->getContent();
    }

    $markers = [
        'clave SOL' => 'Sol-Secreta-Plataforma-1', 'contraseña del certificado' => 'Cert-Pass-Plataforma-2',
        'clave privada' => $key[1], 'cabecera de clave' => 'PRIVATE KEY',
        'producto' => 'Queso andino secreto', 'cliente' => 'CLIENTA CONFIDENCIAL', 'documento del cliente' => '48151623',
        'cliente del ticket' => 'Comprador del ticket reservado', 'importe' => '37.91',
    ];
    expect(count($bodies))->toBeGreaterThanOrEqual(5);
    foreach ($bodies as $uri => $body) {
        foreach ($markers as $name => $marker) {
            expect(str_contains($body, $marker))->toBeFalse("El {$name} aparece en {$uri}");
        }
    }
});

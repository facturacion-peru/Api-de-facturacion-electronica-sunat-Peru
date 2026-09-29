<?php

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\User;
use App\Sales\DocumentPdf;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T062 · RF-007, RF-020, RF-021: PDF A4 y 80 mm con QR y marca de pruebas;
 * descargas del XML firmado y del CDR.
 */

beforeEach(function () {
    ['company' => $this->company, 'seller' => $this->seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $product = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Arroz extra 5 kg', 'sale_price' => '25.90', 'igv_affectation' => '10']);
    ProductLot::factory()->for($product)->quantity('10')->create();

    app()->instance(SunatSender::class, new FakeSunatSender);
    [$this->document] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '2']],
    ], $this->seller);

    $this->download = function (string $path, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)->get("/api/v1/sales-documents/{$this->document->id}/{$path}");
    };
});

it('el QR lleva los campos que exige SUNAT', function () {
    $d = $this->document;

    expect(DocumentPdf::qrPayload($d))->toBe(implode('|', [
        $this->company->ruc, '03', 'B001', '151', '7.90', '51.80', $d->issued_at->format('Y-m-d'), '0', '-', $d->hash,
    ]).'|');
});

it('el PDF muestra el comprobante, los totales, el QR y la marca de pruebas', function (string $format) {
    $html = app(DocumentPdf::class)->html($this->document->load('lines'), $format);

    expect($html)->toContain('BOLETA DE VENTA ELECTRÓNICA')
        ->toContain('B001-00000151')
        ->toContain($this->company->ruc)
        ->toContain('Arroz extra 5 kg')
        ->toContain('CLIENTES VARIOS')
        ->toContain('43.90')->toContain('7.90')->toContain('51.80')
        ->toContain('SON CINCUENTA Y UNO CON 80/100 SOLES')
        ->toContain('PRUEBAS — SIN VALOR LEGAL')
        ->toContain('data:image/png;base64,')
        ->toContain($this->document->hash);
})->with(['a4', '80mm']);

it('descarga el PDF en A4 y en 80 mm', function (string $format) {
    $response = ($this->download)("pdf?format={$format}");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toContain("B001-00000151-{$format}.pdf")
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
})->with(['a4', '80mm']);

it('rechaza un formato desconocido', function () {
    app('auth')->forgetGuards();
    $this->withToken($this->seller->createToken('t')->plainTextToken)
        ->getJson("/api/v1/sales-documents/{$this->document->id}/pdf?format=a5")->assertStatus(422);
});

it('descarga el XML firmado con el nombre de SUNAT', function () {
    $response = ($this->download)('xml');

    $response->assertOk()->assertHeader('content-type', 'application/xml');
    expect($response->headers->get('content-disposition'))->toContain("{$this->company->ruc}-03-B001-151.xml")
        ->and($response->getContent())->toBe($this->document->xml);
});

it('descarga el CDR, o 404 si SUNAT aún no lo devolvió', function () {
    $response = ($this->download)('cdr');
    $response->assertOk()->assertHeader('content-type', 'application/zip');
    expect($response->headers->get('content-disposition'))->toContain("R-{$this->company->ruc}-03-B001-151.zip")
        ->and($response->getContent())->toBe('CDR-ZIP');

    SalesDocument::whereKey($this->document->id)->update(['cdr' => null]);
    app('auth')->forgetGuards();
    $this->withToken($this->seller->createToken('t')->plainTextToken)
        ->getJson("/api/v1/sales-documents/{$this->document->id}/cdr")
        ->assertNotFound()->assertJsonPath('message', 'SUNAT aún no devolvió el CDR de este comprobante.');
});

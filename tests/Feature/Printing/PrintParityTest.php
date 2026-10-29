<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\Ticket;
use App\Printing\DocumentPrint;
use App\Printing\Escpos\EscposBuilder;
use App\Printing\PrintOptions;
use App\Printing\TicketPrint;
use App\Sales\DocumentPdf;
use App\Sales\TicketPdf;
use App\Services\CreditNoteService;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\EscposText;
use Tests\Support\SalesFixture;

/*
 * Spec 015 · T010 y T012 (RF-003, HU-3): la impresión térmica lleva el mismo
 * contenido que el PDF de 80 mm. Cada palabra del PDF (sin etiquetas ni logo)
 * debe aparecer en el texto impreso tantas veces como en el PDF.
 */

/** @return array<string, int> */
function wordCounts(string $text): array
{
    $words = preg_split('/\s+/u', EscposBuilder::sanitize($text), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_filter($words, fn (string $word) => preg_match('/[\p{L}\p{N}]/u', $word) === 1);

    return array_count_values(array_values($words));
}

/** Palabras del PDF que faltan en la impresión (vacío si todo está). */
function missingFromPrint(string $html, string $bytes): array
{
    $html = preg_replace('/<(style|head)\b.*?<\/\1>/su', '', $html);
    $pdf = wordCounts(html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5));
    $printed = wordCounts(implode(' ', EscposText::lines($bytes)));

    $missing = [];
    foreach ($pdf as $word => $count) {
        if (($printed[$word] ?? 0) < $count) {
            $missing[$word] = $count - ($printed[$word] ?? 0);
        }
    }

    return $missing;
}

function expectWithinWidth(string $bytes, int $width): void
{
    $columns = (new PrintOptions($width))->columns();
    foreach (EscposText::lines($bytes) as $line) {
        expect(mb_strlen($line))->toBeLessThanOrEqual($columns);
    }
}

beforeEach(function () {
    $fixture = SalesFixture::issuable();
    ['company' => $this->company, 'seller' => $this->seller] = $fixture;
    $this->fixture = $fixture;
    $this->company->update(['nombre_comercial' => 'Bodega Doña Pepa']);
    app(TenantContext::class)->set($this->company);
    app()->instance(SunatSender::class, new FakeSunatSender);

    $this->rice = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Arroz extra Costeño bolsa de 5 kg', 'sale_price' => '25.90', 'igv_affectation' => '10']);
    $this->bread = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Pan de molde integral', 'sale_price' => '8.50', 'igv_affectation' => '20']);
    ProductLot::factory()->for($this->rice)->quantity('100')->create();
    ProductLot::factory()->for($this->bread)->quantity('100')->create();
});

describe('ticket', function () {
    beforeEach(function () {
        $id = $this->withToken($this->seller->createToken('t')->plainTextToken)->postJson('/api/v1/tickets', [
            'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'yape_plin',
            'customer_name' => 'María Ñahui', 'customer_document' => '46027897',
            'lines' => [
                ['product_id' => $this->rice->id, 'quantity' => '2', 'discount' => '1.50'],
                ['product_id' => $this->bread->id, 'quantity' => '1'],
            ],
        ])->assertCreated()->json('data.id');
        $this->ticket = Ticket::with(['lines', 'seller', 'company'])->findOrFail($id);
    });

    it('lleva todo el texto del PDF de 80 mm', function (int $width) {
        $bytes = app(TicketPrint::class)->render($this->ticket, new PrintOptions($width));

        expect(missingFromPrint(app(TicketPdf::class)->html($this->ticket), $bytes))->toBe([]);
        expectWithinWidth($bytes, $width);
        expect(EscposText::decode($bytes))->toEndWith('[CUT]')
            ->and(EscposText::qrs($bytes))->toBe([]);
    })->with([58, 80]);

    it('un ticket anulado lo indica, igual que el PDF', function () {
        $this->ticket->update(['status' => App\Enums\TicketStatus::Voided]);
        $bytes = app(TicketPrint::class)->render($this->ticket, new PrintOptions(58));

        expect(EscposText::lines($bytes))->toContain('ANULADO')
            ->and(missingFromPrint(app(TicketPdf::class)->html($this->ticket), $bytes))->toBe([]);
    });
});

it('un ticket de 50 líneas se imprime completo, con el TOTAL al final (caso límite)', function () {
    $lines = [];
    for ($i = 1; $i <= 50; $i++) {
        $product = Product::factory()->service()->create(['company_id' => $this->company->id, 'name' => "Servicio número {$i}", 'sale_price' => '1.00']);
        $lines[] = ['product_id' => $product->id, 'quantity' => '1'];
    }
    $id = $this->withToken($this->seller->createToken('t')->plainTextToken)->postJson('/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'lines' => $lines,
    ])->assertCreated()->json('data.id');

    $printed = EscposText::lines(app(TicketPrint::class)->render(Ticket::with(['lines', 'seller', 'company'])->findOrFail($id), new PrintOptions(58)));
    $text = implode("\n", $printed);

    for ($i = 1; $i <= 50; $i++) {
        expect($text)->toContain("Servicio número {$i}\n");
    }
    $total = collect($printed)->search(fn (string $line) => str_starts_with($line, 'TOTAL'));
    expect($total)->toBeGreaterThan(collect($printed)->search(fn (string $line) => str_starts_with($line, 'Servicio número 50')))
        ->and($text)->toContain('S/ 50.00');
});

describe('comprobantes', function () {
    function printedDocument(SalesDocument $document, int $width): array
    {
        $document->load(['lines', 'company', 'reference']);

        return [
            app(DocumentPdf::class)->html($document, '80mm'),
            app(DocumentPrint::class)->render($document, new PrintOptions($width)),
        ];
    }

    it('una boleta con descuento y una línea exonerada lleva todo el texto del PDF, el QR y el resumen', function (int $width) {
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'JOSÉ PEÑA QUISPE', 'address' => 'JR. LOS ÁLAMOS 123, SAN JUAN DE LURIGANCHO, LIMA']);
        [$document] = app(SalesDocumentService::class)->issue([
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash', 'customer_id' => $customer->id,
            'lines' => [
                ['product_id' => $this->rice->id, 'quantity' => '2', 'discount' => '1.80'],
                ['product_id' => $this->bread->id, 'quantity' => '3'],
            ],
        ], $this->seller);

        [$html, $bytes] = printedDocument($document, $width);

        expect(missingFromPrint($html, $bytes))->toBe([])
            ->and(EscposText::qrs($bytes))->toBe([DocumentPdf::qrPayload($document)])
            ->and(implode("\n", EscposText::lines($bytes)))
            ->toContain($document->hash)
            ->toContain('PRUEBAS - SIN VALOR LEGAL');
        expectWithinWidth($bytes, $width);
    })->with([58, 80]);

    it('una factura lleva todo el texto del PDF', function (int $width) {
        $customer = Customer::factory()->withRuc()->create(['company_id' => $this->company->id, 'name' => 'FERRETERÍA Y DISTRIBUIDORA EL SOL DEL NORTE S.A.C.']);
        [$document] = app(SalesDocumentService::class)->issue([
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '01', 'series_id' => $this->fixture['invoice']->id,
            'payment_method' => 'transfer', 'customer_id' => $customer->id,
            'lines' => [['product_id' => $this->rice->id, 'quantity' => '10']],
        ], $this->seller);

        [$html, $bytes] = printedDocument($document, $width);

        expect(missingFromPrint($html, $bytes))->toBe([])
            ->and(EscposText::qrs($bytes))->toBe([DocumentPdf::qrPayload($document)]);
        expectWithinWidth($bytes, $width);
    })->with([58, 80]);

    it('una nota de crédito lleva el documento que modifica, el motivo y todo el texto del PDF', function (int $width) {
        [$boleta] = app(SalesDocumentService::class)->issue([
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->rice->id, 'quantity' => '2']],
        ], $this->seller);
        [$note] = app(CreditNoteService::class)->issue($boleta, [
            'idempotency_key' => (string) Str::uuid(), 'reason_code' => '07', 'reason' => 'Producto dañado en el transporte', 'lines' => [['line_position' => 1, 'quantity' => '1']],
        ], $this->seller);

        [$html, $bytes] = printedDocument($note, $width);
        $text = implode(' ', EscposText::lines($bytes));

        expect(missingFromPrint($html, $bytes))->toBe([])
            ->and($text)->toContain('NOTA DE CRÉDITO ELECTRÓNICA')
            ->and(EscposText::qrs($bytes))->toBe([DocumentPdf::qrPayload($note)]);
        expectWithinWidth($bytes, $width);
    })->with([58, 80]);
});

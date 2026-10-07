<?php

use App\Enums\IgvAffectation;
use App\Enums\PaymentMethod;
use App\Enums\SalesDocumentStatus;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\Series;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\User;
use App\Support\Decimal;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\SalesFixture;
use Tests\Support\SpreadsheetFile;

/*
 * Spec 014 · HU-3: exportar ventas por documento y por línea (T012).
 * CE-002: el neto de lo que «suma como venta» cuadra con el panel de inicio.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 12:00:00');

    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller, 'receipt' => $this->receipt, 'invoice' => $this->invoice, 'receiptNote' => $this->receiptNote] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'P-1', 'name' => 'Gaseosa 500 ml']);

    $this->ticket = function (string $total, string $at, bool $voided = false, ?User $by = null) {
        $ticket = Ticket::factory()->when($voided, fn ($f) => $f->voided())->create([
            'company_id' => $this->company->id, 'seller_id' => ($by ?? $this->seller)->id, 'total' => $total,
            'subtotal' => $total, 'discount_total' => '0.00', 'issued_at' => $at, 'customer_name' => 'Cliente de mostrador',
        ]);
        TicketLine::create([
            'company_id' => $this->company->id, 'ticket_id' => $ticket->id, 'product_id' => $this->product->id, 'position' => 1,
            'product_code' => 'P-1', 'product_name' => 'Gaseosa 500 ml', 'unit' => 'NIU', 'igv_affectation' => IgvAffectation::Gravado,
            'quantity' => '2', 'unit_price' => bcdiv($total, '2', 2), 'gross_amount' => $total, 'discount' => '0.00', 'amount' => $total,
        ]);

        return $ticket;
    };

    $this->document = function (Series $series, SalesDocumentStatus $status, string $total, string $at, array $extra = []) {
        $document = SalesDocument::factory()->status($status)->create([
            'series_id' => $series->id, 'seller_id' => $this->seller->id, 'total' => $total, 'issued_at' => $at,
            'op_gravadas' => bcdiv($total, '1.18', 2), 'igv' => bcsub($total, bcdiv($total, '1.18', 2), 2), ...$extra,
        ]);
        SalesDocumentLine::create([
            'company_id' => $this->company->id, 'sales_document_id' => $document->id, 'product_id' => $this->product->id, 'position' => 1,
            'product_code' => 'P-1', 'product_name' => 'Gaseosa 500 ml', 'unit' => 'NIU', 'igv_affectation' => IgvAffectation::Gravado,
            'quantity' => '1', 'unit_price' => $total, 'unit_value' => bcdiv($total, '1.18', 2), 'gross_amount' => $total, 'discount' => '0.00',
            'base_amount' => bcdiv($total, '1.18', 2), 'igv' => bcsub($total, bcdiv($total, '1.18', 2), 2), 'amount' => $total,
        ]);

        return $document;
    };

    $this->export = function (array $query) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->get('/api/v1/exports/sales?'.http_build_query($query));
    };
    $this->exportJson = function (array $query) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->getJson('/api/v1/exports/sales?'.http_build_query($query));
    };
});

afterEach(fn () => Carbon::setTestNow());

/** Ventas de octubre con todo lo que no suma, más una venta fuera de rango. */
function seedOctober(): void
{
    $t = test();
    ($t->ticket)('10.00', '2026-10-01 09:00:00');
    ($t->ticket)('99.00', '2026-10-02 10:00:00', voided: true);
    $t->boleta = ($t->document)($t->receipt, SalesDocumentStatus::Accepted, '20.00', '2026-10-03 10:10:00', ['customer_document_type' => '1', 'customer_document_number' => '01234567', 'customer_name' => 'Ana Pérez']);
    ($t->document)($t->invoice, SalesDocumentStatus::Rejected, '40.00', '2026-10-04 10:40:00');
    ($t->document)($t->receipt, SalesDocumentStatus::Discarded, '50.00', '2026-10-05 10:50:00');
    ($t->document)($t->receipt, SalesDocumentStatus::Pending, '30.00', '2026-10-06 11:00:00', ['payment_method' => PaymentMethod::YapePlin]);
    $t->note = ($t->document)($t->receiptNote, SalesDocumentStatus::Accepted, '5.00', '2026-10-07 08:00:00', ['reference_document_id' => $t->boleta->id]);
    // Fuera de rango (septiembre) y en el borde de día de Lima (00:00 del 1 de octubre sí entra).
    ($t->ticket)('77.00', '2026-09-30 23:59:59');
    ($t->ticket)('1.50', '2026-10-01 00:00:00');
}

it('HU-3.1 exporta dos hojas, documentos y líneas, con los datos de cada documento', function () {
    seedOctober();

    $response = ($this->export)(['from' => '2026-10-01', 'to' => '2026-10-07']);

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('ventas-2026-10-01_2026-10-07.xlsx');
    $sheets = SpreadsheetFile::sheets($response);
    expect(array_keys($sheets))->toBe(['documentos', 'lineas']);

    $documents = collect($sheets['documentos']);
    expect($documents->pluck('numero')->all())->toHaveCount(8)
        ->and($documents->pluck('numero')->contains('T-'.str_pad((string) Ticket::query()->where('total', '77.00')->value('number'), 6, '0', STR_PAD_LEFT)))->toBeFalse();

    $boleta = $documents->firstWhere('numero', $this->boleta->display_number);
    expect($boleta)->toMatchArray([
        'fecha' => '2026-10-03 10:10:00', 'tipo' => 'Boleta de venta', 'cliente_tipo_documento' => 'DNI',
        'cliente_numero_documento' => '01234567', 'cliente_nombre' => 'Ana Pérez', 'vendedor' => 'Luis',
        'medio_pago' => 'Efectivo', 'op_gravadas' => '16.94', 'igv' => '3.06', 'total' => '20', 'estado' => 'Aceptado',
        'documento_modificado' => '', 'suma_como_venta' => 'si',
    ]);

    $lines = collect($sheets['lineas'])->where('numero', $this->boleta->display_number)->values();
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['tipo' => 'Boleta de venta', 'codigo' => 'P-1', 'producto' => 'Gaseosa 500 ml', 'unidad' => 'NIU', 'cantidad' => '1', 'importe' => '20', 'estado_documento' => 'Aceptado']);
});

it('HU-3.3 la nota de crédito va en negativo y referencia su documento', function () {
    seedOctober();
    $sheets = SpreadsheetFile::sheets(($this->export)(['from' => '2026-10-01', 'to' => '2026-10-07', 'format' => 'csv']));

    $note = collect($sheets['documentos'])->firstWhere('numero', $this->note->display_number);
    expect($note)->toMatchArray(['tipo' => 'Nota de crédito', 'total' => '-5.00', 'igv' => '-0.77', 'documento_modificado' => $this->boleta->display_number, 'suma_como_venta' => 'si'])
        ->and(collect($sheets['lineas'])->firstWhere('numero', $this->note->display_number)['importe'])->toBe('-5.00');
});

it('HU-3.4 marca qué suma como venta según A-54', function () {
    seedOctober();
    $documents = collect(SpreadsheetFile::sheets(($this->export)(['from' => '2026-10-01', 'to' => '2026-10-07']))['documentos']);

    expect($documents->where('suma_como_venta', 'no')->pluck('estado')->sort()->values()->all())
        ->toBe(['Anulado', 'Descartado', 'Rechazado']);
});

it('CE-002 el neto exportado cuadra con el panel de inicio de los mismos días', function () {
    seedOctober();
    $documents = collect(SpreadsheetFile::sheets(($this->export)(['from' => '2026-10-01', 'to' => '2026-10-07', 'format' => 'csv']))['documentos']);
    $exported = Decimal::sum($documents->where('suma_como_venta', 'si')->pluck('total'), 2);

    app('auth')->forgetGuards();
    $days = $this->withToken($this->admin->createToken('t')->plainTextToken)->getJson('/api/v1/dashboard')->json('data.last_7_days');

    // 10 + 1.50 + 20 + 30 − 5 = 56.50
    expect($exported)->toBe('56.50')
        ->and(Decimal::sum(array_column($days, 'total'), 2))->toBe($exported);
});

it('HU-3.2 filtra por tipo y por estado', function () {
    seedOctober();
    $numbers = fn (array $query) => collect(SpreadsheetFile::sheets(($this->export)(['from' => '2026-10-01', 'to' => '2026-10-07', ...$query]))['documentos'])->pluck('tipo')->unique()->sort()->values()->all();

    expect($numbers(['types' => ['ticket']]))->toBe(['Ticket'])
        ->and($numbers(['types' => ['credit_note', 'invoice']]))->toBe(['Factura', 'Nota de crédito'])
        ->and(collect(SpreadsheetFile::sheets(($this->export)(['from' => '2026-10-01', 'to' => '2026-10-07', 'statuses' => ['voided', 'rejected']]))['documentos'])->pluck('estado')->sort()->values()->all())
        ->toBe(['Anulado', 'Rechazado']);
});

it('HU-3.5 rechaza rangos de más de 12 meses o invertidos', function (array $query, string $field) {
    ($this->exportJson)($query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'más de 12 meses' => [['from' => '2025-10-01', 'to' => '2026-10-01'], 'to'],
    'invertido' => [['from' => '2026-10-07', 'to' => '2026-10-01'], 'to'],
    'sin fechas' => [[], 'from'],
    'tipo desconocido' => [['from' => '2026-10-01', 'to' => '2026-10-07', 'types' => ['proforma']], 'types.0'],
]);

it('acepta justo 12 meses', function () {
    ($this->export)(['from' => '2025-10-01', 'to' => '2026-09-30'])->assertOk();
});

it('RF-010 deja la exportación en la auditoría', function () {
    seedOctober();
    ($this->export)(['from' => '2026-10-01', 'to' => '2026-10-07', 'types' => ['ticket']])->assertOk();

    $log = AuditLog::query()->where('action', 'export.sales')->sole();
    expect($log->changes)->toMatchArray([
        'format' => 'xlsx', 'rows' => 3,
        'filters' => ['from' => '2026-10-01', 'to' => '2026-10-07', 'types' => ['ticket'], 'statuses' => []],
    ]);
});

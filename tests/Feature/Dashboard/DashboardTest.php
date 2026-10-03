<?php

use App\Enums\SalesDocumentStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\SalesFixture;

/*
 * Spec 011 · Panel de inicio: GET /api/v1/dashboard (T004).
 * Reglas de A-54 (neto operativo), alcance de A-53 y día de Lima (RF-002).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 12:00:00');

    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller, 'receipt' => $this->receipt, 'invoice' => $this->invoice, 'receiptNote' => $this->receiptNote] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);

    $this->ticket = fn (string $total, string $at, ?User $by = null, bool $voided = false) => Ticket::factory()
        ->when($voided, fn ($f) => $f->voided())
        ->create(['company_id' => $this->company->id, 'seller_id' => ($by ?? $this->seller)->id, 'total' => $total, 'issued_at' => $at]);

    $this->document = fn (Series $series, SalesDocumentStatus $status, string $total, string $at, ?User $by = null, array $extra = []) => SalesDocument::factory()->status($status)
        ->create(['series_id' => $series->id, 'seller_id' => ($by ?? $this->seller)->id, 'total' => $total, 'issued_at' => $at, ...$extra]);

    $this->dashboard = function (?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->getJson('/api/v1/dashboard');
    };
});

afterEach(fn () => Carbon::setTestNow());

/** Ventas de hoy de los dos usuarios, con todo lo que NO debe sumar. */
function seedToday(): void
{
    $t = test();
    ($t->ticket)('10.00', '2026-10-03 09:00:00');
    ($t->ticket)('25.50', '2026-10-03 09:30:00', $t->admin);
    ($t->ticket)('99.00', '2026-10-03 10:00:00', voided: true);

    $t->boleta = ($t->document)($t->receipt, SalesDocumentStatus::Accepted, '20.00', '2026-10-03 10:10:00');
    ($t->document)($t->receipt, SalesDocumentStatus::Pending, '30.00', '2026-10-03 10:20:00', $t->admin);
    ($t->document)($t->receipt, SalesDocumentStatus::Observed, '1.10', '2026-10-03 10:30:00');
    ($t->document)($t->invoice, SalesDocumentStatus::Rejected, '40.00', '2026-10-03 10:40:00', $t->admin);
    ($t->document)($t->receipt, SalesDocumentStatus::Discarded, '50.00', '2026-10-03 10:50:00');

    // Nota aceptada sobre la boleta del vendedor (resta) y nota rechazada (no resta).
    ($t->document)($t->receiptNote, SalesDocumentStatus::Accepted, '5.00', '2026-10-03 11:00:00', $t->admin, ['reference_document_id' => $t->boleta->id]);
    ($t->document)($t->receiptNote, SalesDocumentStatus::Rejected, '7.00', '2026-10-03 11:10:00', $t->admin, ['reference_document_id' => $t->boleta->id]);
}

it('A-54 el administrador ve el neto de hoy de toda la empresa', function () {
    seedToday();

    ($this->dashboard)()->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.date', '2026-10-03')
        ->assertJsonPath('data.scope', 'company')
        // 10 + 25.50 + 20 + 30 + 1.10 − 5 (nota) = 81.60; 2 tickets y 3 comprobantes.
        ->assertJsonPath('data.today.total', '81.60')
        ->assertJsonPath('data.today.count', 5);
});

it('A-53 el vendedor ve solo lo suyo, con las notas sobre sus comprobantes, y sin alertas', function () {
    seedToday();

    ($this->dashboard)($this->seller)->assertOk()
        ->assertJsonPath('data.scope', 'own')
        // 10 + 20 + 1.10 − 5 = 26.10
        ->assertJsonPath('data.today.total', '26.10')
        ->assertJsonPath('data.today.count', 3)
        ->assertJsonPath('data.attention.inventory_alerts', null);
});

it('RF-002 el día es el de Lima: las 23:59:59 son de ayer y las 00:00:01 de hoy', function () {
    ($this->ticket)('3.00', '2026-10-02 23:59:59');
    ($this->ticket)('4.00', '2026-10-03 00:00:01');

    $data = ($this->dashboard)()->assertOk()->json('data');

    expect($data['today'])->toBe(['total' => '4.00', 'count' => 1])
        ->and($data['last_7_days'][5])->toBe(['date' => '2026-10-02', 'total' => '3.00', 'count' => 1]);
});

it('HU-1 devuelve los últimos 7 días, del más antiguo a hoy, con ceros en los días sin ventas', function () {
    ($this->ticket)('5.00', '2026-10-01 08:00:00');
    ($this->ticket)('100.00', '2026-09-26 08:00:00'); // hace 7 días: fuera

    $days = ($this->dashboard)()->assertOk()->json('data.last_7_days');

    expect(array_column($days, 'date'))->toBe(['2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03'])
        ->and($days[4])->toBe(['date' => '2026-10-01', 'total' => '5.00', 'count' => 1])
        ->and($days[0])->toBe(['date' => '2026-09-27', 'total' => '0.00', 'count' => 0]);
});

it('HU-2 cuenta los comprobantes por atender de toda la empresa, también para el vendedor', function () {
    ($this->document)($this->receipt, SalesDocumentStatus::Pending, '1.00', '2026-09-01 10:00:00', $this->admin);
    ($this->document)($this->receipt, SalesDocumentStatus::Sent, '1.00', '2026-10-03 10:00:00', $this->admin);
    ($this->document)($this->invoice, SalesDocumentStatus::Rejected, '1.00', '2026-10-02 10:00:00', $this->admin);
    ($this->document)($this->receipt, SalesDocumentStatus::Accepted, '1.00', '2026-10-03 10:00:00');

    foreach ([$this->admin, $this->seller] as $user) {
        ($this->dashboard)($user)->assertOk()
            ->assertJsonPath('data.attention.pending_documents', 2)
            ->assertJsonPath('data.attention.rejected_documents', 1);
    }
});

it('HU-2 el administrador ve cuántas alertas de inventario hay', function () {
    Product::factory()->create(['company_id' => $this->company->id, 'min_stock' => '5']);

    ($this->dashboard)()->assertOk()->assertJsonPath('data.attention.inventory_alerts', 1);
});

it('HU-3 lista las 5 ventas más recientes entre tickets y comprobantes, según el rol', function () {
    ($this->ticket)('1.00', '2026-10-03 08:00:00');
    ($this->ticket)('2.00', '2026-10-03 08:10:00', $this->admin);
    $boleta = ($this->document)($this->receipt, SalesDocumentStatus::Accepted, '3.00', '2026-10-03 08:20:00');
    ($this->ticket)('4.00', '2026-10-03 08:30:00');
    ($this->ticket)('5.00', '2026-10-03 08:40:00', $this->admin);
    $last = ($this->ticket)('6.00', '2026-10-03 08:50:00');

    $admin = ($this->dashboard)()->json('data.recent_sales');
    expect(array_column($admin, 'total'))->toBe(['6.00', '5.00', '4.00', '3.00', '2.00'])
        ->and($admin[0])->toMatchArray(['kind' => 'ticket', 'id' => $last->id, 'number' => $last->display_number, 'status' => 'issued'])
        ->and($admin[3])->toMatchArray(['kind' => 'document', 'id' => $boleta->id, 'number' => 'B001-'.str_pad((string) $boleta->number, 8, '0', STR_PAD_LEFT), 'document_type' => '03', 'status' => 'accepted']);

    expect(array_column(($this->dashboard)($this->seller)->json('data.recent_sales'), 'total'))->toBe(['6.00', '4.00', '3.00', '1.00']);
});

it('una empresa sin ventas ve ceros y ninguna venta reciente', function () {
    $data = ($this->dashboard)()->assertOk()->json('data');

    expect($data['today'])->toBe(['total' => '0.00', 'count' => 0])
        ->and($data['recent_sales'])->toBe([])
        ->and(array_sum(array_column($data['last_7_days'], 'count')))->toBe(0);
});

it('principio VIII las ventas de otra empresa nunca suman', function () {
    app(TenantContext::class)->clear();
    $other = Company::factory()->withMainEstablishment()->create();
    Ticket::factory()->create(['company_id' => $other->id, 'total' => '500.00', 'issued_at' => '2026-10-03 09:00:00']);
    SalesDocument::factory()->status(SalesDocumentStatus::Rejected)->create(['series_id' => Series::factory()->create(['company_id' => $other->id])->id, 'issued_at' => '2026-10-03 09:00:00']);

    ($this->dashboard)()->assertOk()
        ->assertJsonPath('data.today.total', '0.00')
        ->assertJsonPath('data.attention.rejected_documents', 0)
        ->assertJsonPath('data.recent_sales', []);
});

it('requiere sesión', function () {
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
});

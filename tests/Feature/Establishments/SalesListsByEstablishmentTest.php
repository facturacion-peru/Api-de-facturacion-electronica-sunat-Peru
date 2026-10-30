<?php

use App\Enums\CompanyRole;
use App\Enums\SalesDocumentStatus;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\SalesDocument;
use App\Models\Series;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * T021 · HU-4, RF-007, CE-002 (spec 017): el inicio y las listas de ventas
 * por local o de «Todos»; el detalle de una venta de un local ajeno no existe
 * para el vendedor.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->branch = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->seller->establishments()->attach($this->branch->id, ['company_id' => $this->company->id]);

    $ticket = fn (Establishment $e, string $total) => Ticket::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $e->id, 'total' => $total, 'seller_id' => $this->seller->id]);
    $this->mainTicket = $ticket($this->main, '30.00');
    $this->branchTicket = $ticket($this->branch, '7.00');
    $this->mainSeries = Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->main->id, 'code' => 'B001']);
    $this->branchSeries = Series::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->branch->id, 'code' => 'B002']);
    $this->mainDocument = SalesDocument::factory()->create(['series_id' => $this->mainSeries->id, 'total' => '100.00', 'seller_id' => $this->seller->id, 'status' => SalesDocumentStatus::Pending]);
    $this->branchDocument = SalesDocument::factory()->create(['series_id' => $this->branchSeries->id, 'total' => '20.00', 'seller_id' => $this->seller->id]);

    $this->get = function (string $uri, array $query = [], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->getJson($uri.($query ? '?'.http_build_query($query) : ''));
    };
});

it('el inicio suma todos los locales o el pedido', function () {
    $today = fn (array $q = [], ?User $as = null) => ($this->get)('/api/v1/dashboard', $q, $as)->assertOk()->json('data.today.total');

    expect($today())->toBe('157.00')
        ->and($today(['establishment_id' => $this->main->id]))->toBe('130.00')
        ->and($today(['establishment_id' => $this->branch->id]))->toBe('27.00')
        // El vendedor solo ve lo suyo (A-53) y solo de sus locales.
        ->and($today([], $this->seller))->toBe('27.00');

    expect(($this->get)('/api/v1/dashboard', ['establishment_id' => $this->branch->id])->json('data.attention.pending_documents'))->toBe(0)
        ->and(($this->get)('/api/v1/dashboard')->json('data.attention.pending_documents'))->toBe(1)
        ->and(collect(($this->get)('/api/v1/dashboard', [], $this->seller)->json('data.recent_sales'))->pluck('total')->sort(SORT_NUMERIC)->values()->all())->toBe(['7.00', '20.00']);

    ($this->get)('/api/v1/dashboard', ['establishment_id' => $this->main->id], $this->seller)->assertUnprocessable()->assertJsonValidationErrors(['establishment_id']);
});

it('las listas de tickets y comprobantes filtran por local', function () {
    $ids = fn (string $uri, array $q = [], ?User $as = null) => collect(($this->get)($uri, $q, $as)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('/api/v1/tickets'))->toBe([$this->mainTicket->id, $this->branchTicket->id])
        ->and($ids('/api/v1/tickets', ['establishment_id' => $this->branch->id]))->toBe([$this->branchTicket->id])
        ->and($ids('/api/v1/tickets', [], $this->seller))->toBe([$this->branchTicket->id])
        ->and($ids('/api/v1/sales-documents'))->toBe([$this->mainDocument->id, $this->branchDocument->id])
        ->and($ids('/api/v1/sales-documents', ['establishment_id' => $this->main->id]))->toBe([$this->mainDocument->id])
        ->and($ids('/api/v1/sales-documents', [], $this->seller))->toBe([$this->branchDocument->id]);

    expect(($this->get)('/api/v1/tickets', ['establishment_id' => $this->branch->id])->json('totals'))->toMatchArray(['count' => 1, 'total' => '7.00'])
        ->and(($this->get)('/api/v1/sales-documents', ['establishment_id' => $this->branch->id])->json('counts.pending'))->toBe(0)
        ->and(($this->get)('/api/v1/sales-documents', [], $this->seller)->json('counts.pending'))->toBe(0);
});

it('el vendedor no abre ventas de un local ajeno: 404 como si no existieran', function (string $uri) {
    $uri = str_replace(['{ticket}', '{document}'], [$this->mainTicket->id, $this->mainDocument->id], $uri);

    ($this->get)($uri, [], $this->seller)->assertNotFound();
    // El administrador sí llega (el CDR de un pendiente da 404 por sí mismo).
    if (! str_ends_with($uri, '/cdr')) {
        expect(($this->get)($uri)->status())->not->toBe(404);
    }
})->with([
    '/api/v1/tickets/{ticket}', '/api/v1/tickets/{ticket}/pdf', '/api/v1/tickets/{ticket}/print?width=58',
    '/api/v1/sales-documents/{document}', '/api/v1/sales-documents/{document}/pdf', '/api/v1/sales-documents/{document}/print?width=58',
    '/api/v1/sales-documents/{document}/xml', '/api/v1/sales-documents/{document}/cdr',
]);

it('el vendedor tampoco emite notas sobre comprobantes de un local ajeno', function () {
    app('auth')->forgetGuards();

    $this->withToken($this->seller->createToken('t')->plainTextToken)
        ->postJson("/api/v1/sales-documents/{$this->mainDocument->id}/credit-notes", ['idempotency_key' => (string) Str::uuid(), 'reason' => 'x', 'reason_code' => '01'])
        ->assertNotFound();
});

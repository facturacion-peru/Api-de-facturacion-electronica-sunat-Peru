<?php

use App\Enums\SalesDocumentStatus;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T040 · HU-3 Comprobante rechazado por SUNAT (A-42).
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller, 'receipt' => $this->receipt] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($this->product)->quantity('10')->create();

    $this->issueWith = function (FakeSunatSender $sunat): SalesDocument {
        app()->instance(SunatSender::class, $sunat);
        [$document] = app(SalesDocumentService::class)->issue([
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '3']],
        ], $this->seller);

        return $document;
    };
    $this->discard = function (SalesDocument $document, array $data = ['reason' => 'DNI del cliente mal escrito'], ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)->postJson("/api/v1/sales-documents/{$document->id}/discard", $data);
    };
});

it('el administrador descarta un rechazado: repone el stock y el número queda usado', function () {
    $rejected = ($this->issueWith)(new FakeSunatSender(FakeSunatSender::rejected()));
    expect($this->product->fresh()->stock())->toBe('7.000');

    ($this->discard)($rejected)->assertOk()
        ->assertJsonPath('data.status', 'discarded')
        ->assertJsonPath('data.status_label', 'Descartado')
        ->assertJsonPath('data.discard_reason', 'DNI del cliente mal escrito');

    $next = ($this->issueWith)(new FakeSunatSender);
    expect($this->product->fresh()->stock())->toBe('7.000') // 10 − 3 del nuevo; los del descartado volvieron
        ->and($next->number)->toBe($rejected->number + 1)
        ->and($this->receipt->fresh()->last_number)->toBe($next->number)
        ->and(AuditLog::where('action', 'sales_document.discarded')->sole()->changes)->toMatchArray(['reason' => 'DNI del cliente mal escrito']);
});

it('solo se descartan comprobantes rechazados', function (FakeSunatSender $sunat) {
    $document = ($this->issueWith)($sunat);

    ($this->discard)($document)->assertStatus(422)->assertJsonPath('errors.sales_document.0', 'Solo se descartan comprobantes rechazados por SUNAT.');
})->with([
    'aceptado' => fn () => new FakeSunatSender,
    'pendiente' => fn () => new FakeSunatSender(FakeSunatSender::unreachable()),
]);

it('solo el administrador descarta, y con motivo', function () {
    $rejected = ($this->issueWith)(new FakeSunatSender(FakeSunatSender::rejected()));

    ($this->discard)($rejected, as: $this->seller)->assertForbidden();
    ($this->discard)($rejected, ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    expect($rejected->fresh()->status)->toBe(SalesDocumentStatus::Rejected);
});

it('un descartado no admite notas de crédito ni se descarta dos veces', function () {
    $rejected = ($this->issueWith)(new FakeSunatSender(FakeSunatSender::rejected()));
    ($this->discard)($rejected)->assertOk();

    ($this->discard)($rejected)->assertStatus(422);
    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->postJson("/api/v1/sales-documents/{$rejected->id}/credit-notes", ['idempotency_key' => (string) Str::uuid(), 'reason_code' => '01', 'reason' => 'x'])
        ->assertStatus(422);
});

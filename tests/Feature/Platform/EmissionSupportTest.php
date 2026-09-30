<?php

use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\SalesDocument;
use App\Models\SunatSubmission;
use App\Models\User;
use App\Services\SalesDocumentService;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T040 · HU-5 Soporte de la emisión (A-37): pendientes y rechazados de
 * todas las empresas, sin líneas, cliente ni importes, con reintento.
 */

function issuePendingFor(array $fixture, string $productName): SalesDocument
{
    app(TenantContext::class)->set($fixture['company']);
    $product = Product::factory()->create(['company_id' => $fixture['company']->id, 'name' => $productName, 'sale_price' => '12.34']);
    ProductLot::factory()->for($product)->quantity('5')->create();
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable()));
    [$document] = app(SalesDocumentService::class)->issue([
        'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ], $fixture['seller']);
    app(TenantContext::class)->clear();

    return $document;
}

beforeEach(function () {
    $this->a = SalesFixture::issuable();
    $this->a['company']->update(['razon_social' => 'Bodega Alfa S.A.C.']);
    $this->pendingA = issuePendingFor($this->a, 'Producto secreto de Alfa');
    $this->b = SalesFixture::issuable();
    $this->pendingB = issuePendingFor($this->b, 'Producto secreto de Beta');
    $this->rejectedB = SalesDocument::withoutTenancy()->create([...SalesDocument::factory()->status(SalesDocumentStatus::Rejected)->raw(['series_id' => $this->b['receipt']->id]), 'sunat_code' => '2800', 'sunat_message' => 'Documento del receptor inválido']);
    app(TenantContext::class)->clear();

    $this->root = User::factory()->platformAdmin()->create();
    $this->call = function (string $method, string $uri) {
        app('auth')->forgetGuards();

        return $this->withToken($this->root->createToken('t')->plainTextToken)->json($method, '/api/v1/platform'.$uri);
    };
});

it('HU-5.1 lista pendientes y rechazados de todas las empresas, solo con datos de envío', function () {
    $response = ($this->call)('GET', '/sales-documents')->assertOk();
    $rows = collect($response->json('data'));

    expect($rows->pluck('id')->sort()->values()->all())->toBe(collect([$this->pendingA->id, $this->pendingB->id, $this->rejectedB->id])->sort()->values()->all())
        ->and(array_keys($rows->first()))->toBe([
            'id', 'company', 'display_number', 'document_type', 'document_type_label', 'status', 'status_label',
            'sunat_code', 'sunat_message', 'attempts', 'issued_at', 'next_attempt_at', 'can_retry',
        ])
        ->and($rows->firstWhere('id', $this->pendingA->id)['company'])->toBe(['id' => $this->a['company']->id, 'ruc' => $this->a['company']->ruc, 'razon_social' => 'Bodega Alfa S.A.C.'])
        ->and($response->getContent())->not->toContain('Producto secreto')->not->toContain('12.34')->not->toContain('CLIENTES VARIOS');
});

it('HU-5.1 filtra por estado y por empresa', function () {
    expect(array_column(($this->call)('GET', '/sales-documents?status=rejected')->json('data'), 'id'))->toBe([$this->rejectedB->id])
        ->and(array_column(($this->call)('GET', '/sales-documents?company_id='.$this->a['company']->id)->json('data'), 'id'))->toBe([$this->pendingA->id]);
});

it('HU-5.2 reintenta en el contexto de la empresa y lo audita como acción de la plataforma', function () {
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::accepted()));

    ($this->call)('POST', "/sales-documents/{$this->pendingA->id}/retry")->assertOk()->assertJsonPath('data.status', 'accepted');

    $submission = SunatSubmission::withoutTenancy()->where('sales_document_id', $this->pendingA->id)->latest('id')->first();
    $log = AuditLog::withoutTenancy()->where('action', 'sales_document.retry_requested')->sole();
    expect($submission->trigger)->toBe(SubmissionTrigger::Manual)
        ->and($submission->user_id)->toBe($this->root->id)
        ->and($log->actor_id)->toBe($this->root->id)
        ->and($log->company_id)->toBe($this->a['company']->id);
});

it('HU-5.2 no reintenta un estado definitivo ni un envío en curso', function () {
    ($this->call)('POST', "/sales-documents/{$this->rejectedB->id}/retry")->assertStatus(422);

    SalesDocument::withoutTenancy()->whereKey($this->pendingB->id)->update(['status' => 'sent', 'locked_until' => now()->addMinute()]);
    ($this->call)('POST', "/sales-documents/{$this->pendingB->id}/retry")->assertStatus(409);
});

it('un id inexistente responde 404', function () {
    ($this->call)('POST', '/sales-documents/999999/retry')->assertNotFound();
});

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
use App\Sunat\Sending\SunatResponse;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\SalesFixture;

/*
 * T050 · HU-4 Envío, reintentos y estados (RF-010 a RF-013, RF-022, A-23).
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'seller' => $this->seller] = SalesFixture::issuable();
    app(TenantContext::class)->set($this->company);
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'sale_price' => '10.00']);
    ProductLot::factory()->for($this->product)->quantity('100')->create();

    /** Emite con la SUNAT simulada que se indique y deja ese emisor para lo que siga. */
    $this->issueWith = function (SunatResponse ...$responses): SalesDocument {
        app()->instance(SunatSender::class, $this->sunat = new FakeSunatSender(...$responses));
        [$document] = app(SalesDocumentService::class)->issue([
            'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1']],
        ], $this->seller);

        return $document;
    };

    $this->retry = function (SalesDocument $document, ?User $as = null) {
        app('auth')->forgetGuards();

        return $this->withToken(($as ?? $this->seller)->createToken('t')->plainTextToken)->postJson("/api/v1/sales-documents/{$document->id}/retry");
    };
});

it('aceptado: guarda el CDR, el código y el mensaje', function () {
    $document = ($this->issueWith)(FakeSunatSender::accepted());

    expect($document->status)->toBe(SalesDocumentStatus::Accepted)
        ->and($document->sunat_code)->toBe('0')
        ->and(base64_decode($document->cdr))->toBe('CDR-ZIP')
        ->and($document->next_attempt_at)->toBeNull()
        ->and($document->attempts)->toBe(1);
});

it('observado: guarda las observaciones', function () {
    $document = ($this->issueWith)(FakeSunatSender::observed());

    expect($document->status)->toBe(SalesDocumentStatus::Observed)
        ->and($document->sunat_notes)->toBe(['4287 - El precio unitario no coincide']);
});

it('HU-4.4 rechazado: código y motivo, sin reintentos', function () {
    $document = ($this->issueWith)(FakeSunatSender::rejected());

    expect($document->status)->toBe(SalesDocumentStatus::Rejected)
        ->and($document->sunat_code)->toBe('2800')
        ->and($document->next_attempt_at)->toBeNull();

    $this->travel(2)->hours();
    $this->artisan('sunat:send-pending')->assertSuccessful();
    expect($this->sunat->sent)->toHaveCount(1);
});

it('HU-4.2 sin respuesta: pendiente con espera creciente y luego solo reintento manual', function () {
    $issuedAt = now();
    $document = ($this->issueWith)(FakeSunatSender::unreachable());

    expect($document->status)->toBe(SalesDocumentStatus::Pending)
        ->and($document->next_attempt_at->diffInMinutes($issuedAt, true))->toEqualWithDelta(1, 0.1);

    $waits = [];
    foreach (range(1, 6) as $i) {
        $this->travelTo($document->fresh()->next_attempt_at);
        $before = now();
        $this->artisan('sunat:send-pending')->assertSuccessful();
        $waits[] = (int) round($document->fresh()->next_attempt_at->diffInMinutes($before, true));
    }
    expect($waits)->toBe([2, 5, 10, 30, 60, 60]);

    $this->travelTo($issuedAt->copy()->addHours(23)->addMinutes(30));
    $this->artisan('sunat:send-pending')->assertSuccessful();

    expect($document->fresh()->next_attempt_at)->toBeNull()
        ->and($document->fresh()->status)->toBe(SalesDocumentStatus::Pending);
});

it('HU-4.2 al volver SUNAT, el comando lo envía y queda aceptado', function () {
    $document = ($this->issueWith)(FakeSunatSender::unreachable(), FakeSunatSender::accepted());

    $this->travel(61)->seconds();
    $this->artisan('sunat:send-pending')->assertSuccessful();

    expect($document->fresh()->status)->toBe(SalesDocumentStatus::Accepted)
        ->and($document->fresh()->attempts)->toBe(2)
        ->and(SunatSubmission::where('sales_document_id', $document->id)->pluck('trigger')->map->value->all())->toBe(['issue', 'scheduled']);
});

it('el comando no toca los pendientes que aún no vencen', function () {
    ($this->issueWith)(FakeSunatSender::unreachable());

    $this->artisan('sunat:send-pending')->assertSuccessful();

    expect($this->sunat->sent)->toHaveCount(1);
});

it('el comando retoma un envío cuyo lease caducó (el proceso murió)', function () {
    $document = ($this->issueWith)(FakeSunatSender::unreachable(), FakeSunatSender::accepted());
    $document->update(['status' => SalesDocumentStatus::Sent, 'locked_until' => now()->addMinutes(2), 'next_attempt_at' => null]);

    $this->artisan('sunat:send-pending')->assertSuccessful();
    expect($this->sunat->sent)->toHaveCount(1);

    $this->travel(3)->minutes();
    $this->artisan('sunat:send-pending')->assertSuccessful();
    expect($document->fresh()->status)->toBe(SalesDocumentStatus::Accepted);
});

it('HU-4.5 «registrado previamente» queda aceptado con la nota', function () {
    $document = ($this->issueWith)(FakeSunatSender::unreachable(), FakeSunatSender::alreadyRegistered());
    ($this->retry)($document)->assertOk();

    expect($document->fresh()->status)->toBe(SalesDocumentStatus::Accepted)
        ->and($document->fresh()->sunat_notes)->toBe([SunatResponse::ALREADY_REGISTERED_NOTE]);
});

it('HU-4.3 «Reintentar» reenvía de inmediato y se audita', function () {
    $document = ($this->issueWith)(FakeSunatSender::unreachable(), FakeSunatSender::accepted());

    ($this->retry)($document)->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(SunatSubmission::where('sales_document_id', $document->id)->latest('id')->first())
        ->trigger->toBe(SubmissionTrigger::Manual)
        ->user_id->toBe($this->seller->id)
        ->and(AuditLog::where('action', 'sales_document.retry_requested')->count())->toBe(1)
        ->and(AuditLog::where('action', 'sales_document.status_changed')->sole()->changes['status'])->toBe(['from' => 'pending', 'to' => 'accepted']);
});

it('HU-4.3 «Reintentar» responde 409 si hay un envío en curso', function () {
    $document = ($this->issueWith)(FakeSunatSender::unreachable());
    $document->update(['status' => SalesDocumentStatus::Sent, 'locked_until' => now()->addMinutes(2)]);

    ($this->retry)($document)->assertStatus(409)->assertJsonPath('message', 'Este comprobante ya se está enviando a SUNAT.');
    expect($this->sunat->sent)->toHaveCount(1);
});

it('HU-4.3 «Reintentar» no aplica a un estado definitivo', function () {
    $document = ($this->issueWith)(FakeSunatSender::accepted());

    ($this->retry)($document)->assertStatus(422)->assertJsonPath('errors.status.0', 'El comprobante ya tiene una respuesta definitiva de SUNAT (Aceptado).');
});

it('cada intento queda registrado con su resultado', function () {
    $document = ($this->issueWith)(FakeSunatSender::unreachable(), FakeSunatSender::unreachable(), FakeSunatSender::accepted());
    ($this->retry)($document)->assertOk();
    ($this->retry)($document)->assertOk();

    expect(SunatSubmission::where('sales_document_id', $document->id)->orderBy('id')->pluck('result')->map->value->all())
        ->toBe(['unreachable', 'unreachable', 'accepted']);
});

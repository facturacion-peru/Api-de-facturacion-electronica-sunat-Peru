<?php

use App\Enums\CompanyRole;
use App\Enums\SalesDocumentStatus;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\SalesDocument;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\SalesFixture;

/*
 * T020 · HU-1 Ver las empresas y su estado (A-37: solo metadatos y contadores).
 */

beforeEach(function () {
    // A: emisión validada, un pendiente y un rechazado, dos usuarios.
    ['company' => $this->a, 'admin' => $this->adminA, 'receipt' => $receipt] = SalesFixture::issuable();
    $this->a->update(['razon_social' => 'Bodega Alfa S.A.C.']);
    SalesDocument::factory()->status(SalesDocumentStatus::Pending)->create(['series_id' => $receipt->id, 'issued_at' => '2026-09-20 10:00:00']);
    SalesDocument::factory()->status(SalesDocumentStatus::Rejected)->create(['series_id' => $receipt->id, 'issued_at' => '2026-09-21 10:00:00']);
    SalesDocument::factory()->status(SalesDocumentStatus::Accepted)->create(['series_id' => $receipt->id, 'issued_at' => '2026-09-22 10:00:00']);
    Ticket::factory()->create(['company_id' => $this->a->id, 'issued_at' => '2026-09-25 18:30:00']);

    // B: sin configurar SUNAT, administrador invitado que aún no acepta.
    app(TenantContext::class)->clear();
    $this->b = Company::factory()->withMainEstablishment()->create(['razon_social' => 'Ferretería Beta E.I.R.L.']);
    Invitation::factory()->create(['company_id' => $this->b->id, 'email' => 'jefe@beta.pe', 'role' => CompanyRole::CompanyAdmin]);

    // C: suspendida, sin problemas de emisión conocidos.
    app(TenantContext::class)->clear();
    ['company' => $this->c] = SalesFixture::issuable();
    $this->c->update(['razon_social' => 'Comercial Gamma S.A.', 'active' => false]);
    app(TenantContext::class)->clear();

    $root = User::factory()->platformAdmin()->create();
    $this->get = function (string $uri) use ($root) {
        app('auth')->forgetGuards();

        return $this->withToken($root->createToken('t')->plainTextToken)->getJson('/api/v1/platform'.$uri);
    };
});

it('HU-1.1 muestra por empresa su estado, el de SUNAT y los contadores', function () {
    $rows = collect(($this->get)('/companies')->assertOk()->json('data'))->keyBy('razon_social');

    expect($rows['Bodega Alfa S.A.C.'])->toMatchArray([
        'active' => true, 'sunat_status' => 'validated', 'users_count' => 2, 'pending_documents' => 1, 'rejected_documents' => 1,
    ])->and($rows['Ferretería Beta E.I.R.L.'])->toMatchArray([
        'sunat_status' => 'not_configured', 'users_count' => 0, 'pending_documents' => 0, 'rejected_documents' => 0,
    ])->and($rows['Comercial Gamma S.A.'])->toMatchArray(['active' => false, 'sunat_status' => 'inactive']);
});

it('HU-1.2 busca por RUC o razón social', function () {
    expect(array_column(($this->get)('/companies?search=ferre')->json('data'), 'razon_social'))->toBe(['Ferretería Beta E.I.R.L.'])
        ->and(array_column(($this->get)('/companies?search='.$this->a->ruc)->json('data'), 'razon_social'))->toBe(['Bodega Alfa S.A.C.']);
});

it('HU-1.2 filtra por estado y por «con problemas de emisión»', function () {
    expect(array_column(($this->get)('/companies?status=inactive')->json('data'), 'razon_social'))->toBe(['Comercial Gamma S.A.'])
        ->and(array_column(($this->get)('/companies?issues=1')->json('data'), 'razon_social'))->toBe(['Bodega Alfa S.A.C.', 'Ferretería Beta E.I.R.L.']);
});

it('HU-1.3 la ficha trae administrador, usuarios activos y última actividad', function () {
    $a = ($this->get)("/companies/{$this->a->id}")->assertOk()->json('data');
    $b = ($this->get)("/companies/{$this->b->id}")->json('data');

    expect($a['admin'])->toBe(['name' => $this->adminA->name, 'email' => $this->adminA->email, 'invitation' => 'accepted'])
        ->and($a['active_users'])->toBe(2)
        ->and($a['last_activity_at'])->toStartWith('2026-09-25')
        ->and($a['fiscal_address'])->toHaveKeys(['address', 'ubigeo', 'district'])
        ->and($b['admin'])->toBe(['name' => null, 'email' => 'jefe@beta.pe', 'invitation' => 'pending'])
        ->and($b['last_activity_at'])->toBeNull();
});

it('valida los filtros', function () {
    ($this->get)('/companies?status=borrada')->assertStatus(422)->assertJsonValidationErrors(['status']);
});

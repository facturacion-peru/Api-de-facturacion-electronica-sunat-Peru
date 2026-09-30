<?php

use App\Models\User;
use Tests\Support\SalesFixture;

/*
 * T051 · Contrato de los recursos del panel de la plataforma (principio III).
 * Son listas blancas (A-37): un campo nuevo debe decidirse a propósito.
 */

it('la lista y la ficha de empresas exponen exactamente los campos declarados', function () {
    ['company' => $company] = SalesFixture::issuable();
    $root = User::factory()->platformAdmin()->create();
    $token = $root->createToken('t')->plainTextToken;

    $row = $this->withToken($token)->getJson('/api/v1/platform/companies')->json('data.0');
    app('auth')->forgetGuards();
    $detail = $this->withToken($token)->getJson("/api/v1/platform/companies/{$company->id}")->json('data');

    $summary = ['id', 'ruc', 'razon_social', 'nombre_comercial', 'active', 'sunat_status', 'sunat_status_label', 'sunat_reason',
        'users_count', 'pending_documents', 'rejected_documents', 'created_at'];

    expect(array_keys($row))->toBe($summary)
        ->and(array_keys($detail))->toBe([...$summary, 'person_type', 'tax_regime', 'tax_regime_label', 'email', 'phone',
            'fiscal_address', 'admin', 'active_users', 'last_activity_at'])
        ->and(array_keys($detail['admin']))->toBe(['name', 'email', 'invitation']);
});

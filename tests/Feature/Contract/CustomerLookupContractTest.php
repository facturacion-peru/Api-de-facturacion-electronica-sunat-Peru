<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * Spec 018 · T012: contrato de la búsqueda por DNI o RUC (principio III).
 */

it('la búsqueda responde { success, data, meta: { source, reason } }', function () {
    config(['services.apisperu.token' => 'token-de-prueba']);
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'No se encontraron resultados.'])]);
    $seller = User::factory()->forCompany(Company::factory()->withMainEstablishment()->create())->create();

    $json = $this->withToken($seller->createToken('t')->plainTextToken)
        ->postJson('/api/v1/customers/lookup', ['document_type' => '1', 'document_number' => '46027897'])
        ->assertOk()->json();

    expect(array_keys($json))->toBe(['success', 'data', 'meta'])
        ->and(array_keys($json['meta']))->toBe(['source', 'reason']);
});

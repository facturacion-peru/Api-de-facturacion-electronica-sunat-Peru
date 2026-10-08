<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\ImportFiles;

/*
 * Spec 014 · T024 · Contrato de la vista previa y la confirmación de una
 * importación (principio III). El frontend lo tipa a mano en
 * features/data-transfer/types.ts.
 */

it('expone exactamente las claves de la vista previa, de cada cambio y de la confirmación', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $admin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($company);
    Product::factory()->create(['company_id' => $company->id, 'code' => 'ACE-1', 'sale_price' => '3.50', 'name' => 'Aceite']);
    $token = $admin->createToken('t')->plainTextToken;

    $json = $this->withToken($token)->postJson('/api/v1/imports/products/preview', ['mode' => 'upsert', 'file' => ImportFiles::make(
        ['codigo', 'nombre', 'tipo', 'unidad', 'precio_venta', 'afectacion_igv', 'color'],
        [['ACE-1', 'Aceite', 'bien', 'NIU', '3.80', '10', ''], ['NUEVO', 'Nuevo', 'bien', 'NIU', '1', '10', '']],
    )])->assertCreated()->json();

    expect(array_keys($json))->toBe(['data', 'success'])
        ->and(array_keys($json['data']))->toBe(['id', 'kind', 'mode', 'expires_at', 'can_confirm', 'summary', 'errors', 'warnings', 'changes'])
        ->and(array_keys($json['data']['summary']))->toBe(['rows', 'create', 'update', 'unchanged', 'errors'])
        ->and(array_keys($json['data']['warnings'][0]))->toBe(['row', 'column', 'message'])
        ->and(array_keys($json['data']['changes'][0]))->toBe(['row', 'action', 'key', 'name', 'fields'])
        ->and(array_keys($json['data']['changes'][0]['fields']['precio_venta']))->toBe(['from', 'to'])
        ->and(array_keys($json['data']['changes'][1]))->toBe(['row', 'action', 'key', 'name', 'stock']);

    app('auth')->forgetGuards();
    $confirmed = $this->withToken($token)->postJson("/api/v1/imports/{$json['data']['id']}/confirm")->assertOk()->json();
    expect(array_keys($confirmed))->toBe(['success', 'data'])
        ->and(array_keys($confirmed['data']))->toBe(['created', 'updated', 'entries']);
});

it('cada error lleva fila, columna y mensaje', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $admin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();

    $json = $this->withToken($admin->createToken('t')->plainTextToken)->postJson('/api/v1/imports/products/preview', ['file' => ImportFiles::make(
        ['codigo', 'nombre', 'tipo', 'unidad', 'precio_venta', 'afectacion_igv'], [['A', 'B', 'otro', 'NIU', '1', '10']],
    )])->json();

    expect(array_keys($json['data']['errors'][0]))->toBe(['row', 'column', 'message']);
});

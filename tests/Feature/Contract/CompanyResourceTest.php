<?php

use App\Http\Resources\CompanyResource;
use App\Models\Company;

/*
 * T033 · Contrato de CompanyResource. El spec OpenAPI aún no documenta
 * respuestas (principio III): esta prueba detecta cualquier cambio de forma
 * que obligue a actualizar los tipos escritos a mano en el frontend.
 */

it('expone exactamente los campos declarados', function () {
    $company = Company::factory()->withMainEstablishment()->create()->load('mainEstablishment.district');

    $data = CompanyResource::make($company)->resolve();

    expect(array_keys($data))->toBe([
        'id', 'ruc', 'razon_social', 'nombre_comercial', 'person_type', 'tax_regime',
        'email', 'phone', 'logo_url', 'active', 'fiscal_address', 'created_at',
    ])->and(array_keys($data['fiscal_address']))->toBe(['address', 'ubigeo', 'district']);
});

it('responde con el sobre { success, data }', function () {
    $company = Company::factory()->withMainEstablishment()->create();

    $json = CompanyResource::make($company)->response()->getData(true);

    expect(array_keys($json))->toBe(['data', 'success'])->and($json['success'])->toBeTrue();
});

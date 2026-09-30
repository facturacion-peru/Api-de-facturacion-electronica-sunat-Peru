<?php

use App\Models\User;
use Database\Factories\EstablishmentFactory;

/*
 * T030 · El panel busca ubigeos para el alta y la corrección de datos legales.
 */

it('el administrador de la plataforma busca ubigeos', function () {
    EstablishmentFactory::ensureLimaUbigeo();
    $root = User::factory()->platformAdmin()->create();

    $data = $this->withToken($root->createToken('t')->plainTextToken)->getJson('/api/v1/platform/ubigeos/search?q=Lima')->assertOk()->json('data');

    expect(collect($data)->pluck('id'))->toContain(EstablishmentFactory::LIMA)
        ->and($data[0])->toHaveKeys(['id', 'nombre', 'provincia', 'region', 'ubigeo_completo']);
});

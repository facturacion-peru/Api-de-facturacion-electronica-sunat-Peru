<?php

use App\DocumentLookup\ApisPeruClient;
use App\DocumentLookup\LookupStatus;
use App\Enums\CustomerDocumentType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * T004 · Spec 018: traducción de las respuestas de apisperu, con la forma
 * confirmada en T002 (verificacion.md). El token nunca sale del servidor.
 */

const TEST_TOKEN = 'token-de-prueba-secreto';

beforeEach(function () {
    config(['services.apisperu.token' => TEST_TOKEN, 'services.apisperu.base_url' => 'https://dniruc.test/api/v1']);
    $this->client = app(ApisPeruClient::class);
});

it('traduce un RUC con razón social, dirección, ubigeo y estado normalizados', function () {
    Http::fake(['dniruc.test/api/v1/ruc/20131312955*' => Http::response([
        'ruc' => '20131312955', 'razonSocial' => '  SUPERINTENDENCIA   NACIONAL - SUNAT ', 'nombreComercial' => null,
        'estado' => 'activo', 'condicion' => ' habido', 'direccion' => 'AV. GARCILASO DE LA VEGA NRO. 1472 LIMA LIMA LIMA',
        'departamento' => 'LIMA', 'provincia' => 'LIMA', 'distrito' => 'LIMA', 'ubigeo' => '150101',
    ])]);

    $outcome = $this->client->find(CustomerDocumentType::Ruc, '20131312955');

    expect($outcome->status)->toBe(LookupStatus::Found)
        ->and($outcome->data->name)->toBe('SUPERINTENDENCIA NACIONAL - SUNAT')
        ->and($outcome->data->address)->toBe('AV. GARCILASO DE LA VEGA NRO. 1472 LIMA LIMA LIMA')
        ->and($outcome->data->ubigeo)->toBe('150101')
        ->and($outcome->data->rucStatus)->toBe('ACTIVO')
        ->and($outcome->data->rucCondition)->toBe('HABIDO');
});

it('traduce un DNI con el nombre como «APELLIDOS NOMBRES», sin dirección', function () {
    Http::fake(['dniruc.test/api/v1/dni/46027897*' => Http::response([
        'success' => true, 'dni' => '46027897', 'nombres' => 'María José', 'apellidoPaterno' => 'Quispe', 'apellidoMaterno' => 'Ñahui',
        'codVerifica' => 3, 'codVerificaLetra' => 'C',
    ])]);

    $outcome = $this->client->find(CustomerDocumentType::Dni, '46027897');

    expect($outcome->status)->toBe(LookupStatus::Found)
        ->and($outcome->data->name)->toBe('QUISPE ÑAHUI MARÍA JOSÉ')
        ->and($outcome->data->address)->toBeNull()
        ->and($outcome->data->rucStatus)->toBeNull();
});

it('un RUC de baja trae su estado y condición tal cual, normalizados', function () {
    Http::fake(['*' => Http::response(['ruc' => '20100047218', 'razonSocial' => 'EMPRESA CERRADA S.A.', 'estado' => 'BAJA DE OFICIO', 'condicion' => 'NO HABIDO', 'direccion' => '-'])]);

    $data = $this->client->find(CustomerDocumentType::Ruc, '20100047218')->data;

    expect([$data->rucStatus, $data->rucCondition])->toBe(['BAJA DE OFICIO', 'NO HABIDO'])
        ->and($data->address)->toBeNull();
});

it('traduce cada falla del servicio', function (Closure $response, LookupStatus $status) {
    Http::fake(['*' => $response]);

    expect($this->client->find(CustomerDocumentType::Ruc, '20131312955')->status)->toBe($status);
})->with([
    'no encontrado (200 con success false)' => [fn () => Http::response(['success' => false, 'message' => 'No se encontraron resultados.']), LookupStatus::NotFound],
    'no encontrado (404)' => [fn () => Http::response(['message' => 'Not found'], 404), LookupStatus::NotFound],
    'sin cupo (429)' => [fn () => Http::response(['message' => 'Too many requests'], 429), LookupStatus::Quota],
    'sin cupo (mensaje)' => [fn () => Http::response(['success' => false, 'message' => 'Superó el límite de consultas diarias.']), LookupStatus::Quota],
    'servicio caído' => [fn () => Http::response('Bad gateway', 502), LookupStatus::Unavailable],
    'token inválido' => [fn () => Http::response(['message' => 'Token inválido.'], 401), LookupStatus::Unavailable],
    'respuesta sin nombre' => [fn () => Http::response(['ruc' => '20131312955', 'razonSocial' => '']), LookupStatus::NotFound],
]);

it('un tiempo agotado o un error de red es «no disponible»', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect($this->client->find(CustomerDocumentType::Dni, '46027897')->status)->toBe(LookupStatus::Unavailable);
});

it('sin token no llama al servicio', function () {
    config(['services.apisperu.token' => null]);
    Http::fake();

    expect(app(ApisPeruClient::class)->find(CustomerDocumentType::Ruc, '20131312955')->status)->toBe(LookupStatus::Disabled);
    Http::assertNothingSent();
});

it('el token va solo en la consulta al servicio y nunca en el log', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['message' => 'Token inválido.'], 401)]);

    $this->client->find(CustomerDocumentType::Ruc, '20131312955');

    Http::assertSent(fn (Request $request) => $request['token'] === TEST_TOKEN && str_contains($request->url(), '/ruc/20131312955'));
    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
        $all = $message.json_encode($context);

        return ! str_contains($all, TEST_TOKEN) && ! str_contains($all, '20131312955');
    });
});

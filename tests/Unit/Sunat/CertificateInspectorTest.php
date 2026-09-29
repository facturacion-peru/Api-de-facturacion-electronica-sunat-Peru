<?php

use App\Sunat\CertificateInspector;
use App\Sunat\Exceptions\InvalidCertificate;
use Illuminate\Support\Carbon;
use Tests\Support\TestCertificates;

/*
 * T020 · RF-003: el certificado se valida al recibirlo y, si falla, se dice
 * exactamente por qué (HU-1.2/1.3).
 */

function inspect(string $contents, ?string $password, string $ruc = '20131312955')
{
    return (new CertificateInspector)->inspect($contents, $password, $ruc);
}

function inspectError(string $contents, ?string $password, string $ruc = '20131312955'): string
{
    try {
        inspect($contents, $password, $ruc);
    } catch (InvalidCertificate $e) {
        return $e->getMessage();
    }

    return 'no lanzó excepción';
}

afterEach(fn () => Carbon::setTestNow());

it('lee un .pfx válido: metadatos y PEM con la clave privada', function () {
    $result = inspect(TestCertificates::make()['pfx'], 'clave-cert-123');

    expect($result->ruc)->toBe('20131312955')
        ->and($result->subject)->toContain('EMPRESA DE PRUEBA')
        ->and($result->validTo->isFuture())->toBeTrue()
        ->and($result->pem)->toContain('BEGIN CERTIFICATE')
        ->and($result->pem)->toContain('PRIVATE KEY');
});

it('lee un .pem con certificado y clave privada', function () {
    expect(inspect(TestCertificates::make()['pem'], null)->ruc)->toBe('20131312955');
});

it('encuentra el RUC en serialNumber, OU o CN', function (string $field) {
    expect(inspect(TestCertificates::make(rucField: $field)['pfx'], 'clave-cert-123')->ruc)->toBe('20131312955');
})->with(['serialNumber', 'organizationalUnitName', 'commonName']);

it('rechaza una contraseña incorrecta', function () {
    expect(inspectError(TestCertificates::make()['pfx'], 'otra-clave'))->toBe('La contraseña del certificado es incorrecta.');
});

it('rechaza un archivo dañado', function () {
    expect(inspectError('esto no es un certificado', 'clave-cert-123'))->toBe('El archivo no es un certificado válido o está dañado.');
});

it('rechaza un certificado vencido', function () {
    $pfx = TestCertificates::make(days: 1)['pfx'];
    Carbon::setTestNow(now()->addDays(3));

    expect(inspectError($pfx, 'clave-cert-123'))->toStartWith('El certificado venció el ');
});

it('rechaza un certificado sin clave privada', function () {
    expect(inspectError(TestCertificates::make()['cert_only'], null))->toBe('El certificado no incluye la clave privada, necesaria para firmar.');
});

it('rechaza un certificado de otro RUC', function () {
    expect(inspectError(TestCertificates::make(ruc: '20100070970')['pfx'], 'clave-cert-123'))
        ->toBe('El certificado es del RUC 20100070970, no del de la empresa (20131312955).');
});

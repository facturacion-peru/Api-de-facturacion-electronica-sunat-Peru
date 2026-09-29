<?php

namespace Tests\Support;

/**
 * Certificados autofirmados reales para pruebas (spec 004). SUNAT pone el
 * RUC en serialNumber, OU o CN según el emisor; se puede elegir el campo.
 */
final class TestCertificates
{
    private static ?string $signingPem = null;

    /**
     * PEM (certificado + clave) para firmar en pruebas de emisión. Se genera
     * una vez por proceso: firmar no depende del RUC del titular.
     */
    public static function signingPem(): string
    {
        return self::$signingPem ??= self::make()['pem'];
    }

    /**
     * @param  'serialNumber'|'organizationalUnitName'|'commonName'  $rucField
     * @return array{pfx: string, pem: string, cert_only: string}
     */
    public static function make(string $ruc = '20131312955', string $password = 'clave-cert-123', int $days = 365, string $rucField = 'serialNumber'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $dn = ['countryName' => 'PE', 'organizationName' => 'EMPRESA DE PRUEBA S.A.C.', 'commonName' => 'EMPRESA DE PRUEBA'];
        $dn[$rucField] = $rucField === 'commonName' ? "EMPRESA DE PRUEBA {$ruc}" : $ruc;

        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));

        openssl_pkcs12_export($cert, $pfx, $key, $password);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return ['pfx' => $pfx, 'pem' => $certPem.$keyPem, 'cert_only' => $certPem];
    }
}

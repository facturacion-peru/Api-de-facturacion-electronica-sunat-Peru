<?php

namespace App\Sunat;

use App\Sunat\Exceptions\InvalidCertificate;
use Illuminate\Support\Carbon;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Lee un certificado .pfx/.p12 (con contraseña) o .pem y comprueba que sirve
 * para firmar comprobantes de la empresa: contraseña correcta, no vencido,
 * con clave privada y del mismo RUC (RF-003).
 */
class CertificateInspector
{
    public function inspect(string $contents, ?string $password, string $expectedRuc): InspectedCertificate
    {
        [$certificate, $key] = str_contains($contents, '-----BEGIN')
            ? $this->readPem($contents, $password)
            : $this->readPkcs12($contents, (string) $password);

        if ($key === null) {
            throw new InvalidCertificate('El certificado no incluye la clave privada, necesaria para firmar.');
        }

        if (! openssl_x509_check_private_key($certificate, $key)) {
            throw new InvalidCertificate('La clave privada no corresponde al certificado.');
        }

        $info = openssl_x509_parse($certificate);
        $validTo = Carbon::createFromTimestamp($info['validTo_time_t']);

        if ($validTo->isPast()) {
            throw new InvalidCertificate('El certificado venció el '.$validTo->format('d/m/Y').'.');
        }

        $ruc = $this->findRuc($info['subject'] ?? []);

        if ($ruc === null) {
            throw new InvalidCertificate('No se encontró el RUC en el certificado.');
        }

        if ($ruc !== $expectedRuc) {
            throw new InvalidCertificate("El certificado es del RUC {$ruc}, no del de la empresa ({$expectedRuc}).");
        }

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return new InspectedCertificate(
            pem: $certificatePem.$keyPem,
            subject: $this->subjectLine($info['subject'] ?? []),
            ruc: $ruc,
            serialNumber: $info['serialNumberHex'] ?? null,
            validFrom: Carbon::createFromTimestamp($info['validFrom_time_t']),
            validTo: $validTo,
        );
    }

    /** @return array{OpenSSLCertificate, ?OpenSSLAsymmetricKey} */
    private function readPkcs12(string $contents, string $password): array
    {
        $this->clearErrors();

        if (! openssl_pkcs12_read($contents, $parts, $password)) {
            $errors = $this->collectErrors();

            throw new InvalidCertificate(str_contains($errors, 'mac verify failure')
                ? 'La contraseña del certificado es incorrecta.'
                : 'El archivo no es un certificado válido o está dañado.');
        }

        $certificate = openssl_x509_read($parts['cert']);
        $key = isset($parts['pkey']) ? openssl_pkey_get_private($parts['pkey']) : false;

        return [$certificate, $key ?: null];
    }

    /** @return array{OpenSSLCertificate, ?OpenSSLAsymmetricKey} */
    private function readPem(string $contents, ?string $password): array
    {
        $certificate = @openssl_x509_read($contents);

        if ($certificate === false) {
            throw new InvalidCertificate('El archivo no es un certificado válido o está dañado.');
        }

        if (! str_contains($contents, 'PRIVATE KEY')) {
            return [$certificate, null];
        }

        $key = @openssl_pkey_get_private($contents, $password ?? '');

        if ($key === false) {
            throw new InvalidCertificate('La contraseña del certificado es incorrecta.');
        }

        return [$certificate, $key];
    }

    /**
     * SUNAT y las entidades certificadoras ponen el RUC en serialNumber, OU o CN.
     *
     * @param  array<string, string|list<string>>  $subject
     */
    private function findRuc(array $subject): ?string
    {
        foreach (['serialNumber', 'OU', 'CN'] as $field) {
            foreach ((array) ($subject[$field] ?? []) as $value) {
                if (preg_match('/\b((?:10|15|17|20)\d{9})\b/', (string) $value, $match)) {
                    return $match[1];
                }
            }
        }

        return null;
    }

    /** @param  array<string, string|list<string>>  $subject */
    private function subjectLine(array $subject): string
    {
        return collect($subject)
            ->map(fn ($value, $field) => $field.'='.implode(', ', (array) $value))
            ->implode(', ');
    }

    private function clearErrors(): void
    {
        while (openssl_error_string() !== false) {
            // vacía la cola de errores de OpenSSL
        }
    }

    private function collectErrors(): string
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return implode(' ', $errors);
    }
}

<?php

namespace App\Sunat;

/** XML firmado y su hash (DigestValue), que va en el QR del PDF. */
final readonly class SignedXml
{
    public function __construct(
        public string $xml,
        public string $hash,
    ) {}
}

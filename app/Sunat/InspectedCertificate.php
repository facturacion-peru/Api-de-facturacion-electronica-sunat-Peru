<?php

namespace App\Sunat;

use Illuminate\Support\Carbon;

/** Resultado de leer un certificado: PEM para firmar y metadatos públicos. */
final readonly class InspectedCertificate
{
    public function __construct(
        public string $pem,
        public string $subject,
        public string $ruc,
        public ?string $serialNumber,
        public Carbon $validFrom,
        public Carbon $validTo,
    ) {}
}

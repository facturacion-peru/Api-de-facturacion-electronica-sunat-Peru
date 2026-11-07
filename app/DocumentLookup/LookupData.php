<?php

namespace App\DocumentLookup;

/** Lo que se guarda de una respuesta: solo lo que va en el comprobante (A-93 ⚖️). */
final readonly class LookupData
{
    public function __construct(
        public string $name,
        public ?string $address = null,
        public ?string $ubigeo = null,
        public ?string $rucStatus = null,
        public ?string $rucCondition = null,
    ) {}
}

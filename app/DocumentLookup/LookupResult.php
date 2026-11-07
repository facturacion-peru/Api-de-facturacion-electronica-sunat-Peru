<?php

namespace App\DocumentLookup;

use App\Models\Customer;

/** Lo que devuelve la búsqueda de un cliente (spec 018). */
final readonly class LookupResult
{
    /**
     * @param  'company'|'platform'|'service'|null  $source  De dónde salieron los datos.
     */
    public function __construct(
        public ?Customer $customer,
        public ?string $source,
        public ?LookupStatus $reason = null,
    ) {}
}

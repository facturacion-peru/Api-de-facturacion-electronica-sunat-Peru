<?php

namespace App\DocumentLookup;

final readonly class LookupOutcome
{
    public function __construct(
        public LookupStatus $status,
        public ?LookupData $data = null,
    ) {}

    public static function failed(LookupStatus $status): self
    {
        return new self($status);
    }
}

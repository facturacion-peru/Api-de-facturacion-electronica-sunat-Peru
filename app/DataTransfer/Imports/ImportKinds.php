<?php

namespace App\DataTransfer\Imports;

/** Tipos de dato importables (A-68): productos y clientes. */
class ImportKinds
{
    public const ALL = ['products', 'customers'];

    public function for(string $kind): RowImport
    {
        return match ($kind) {
            'products' => app(ProductImport::class),
            'customers' => app(CustomerImport::class),
        };
    }
}

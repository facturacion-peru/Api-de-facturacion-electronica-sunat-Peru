<?php

namespace App\DataTransfer\Imports;

use App\Models\User;

/** Importación de clientes (spec 014, HU-5). Se completa en T022. */
class CustomerImport implements RowImport
{
    public function requiredColumns(): array
    {
        return [];
    }

    public function knownColumns(): array
    {
        return [];
    }

    public function readOnlyColumns(): array
    {
        return [];
    }

    public function analyze(array $records, array $columns, string $mode): Analysis
    {
        return new Analysis;
    }

    public function apply(array $row, User $actor, array &$result): void {}
}

<?php

namespace App\DataTransfer\Imports;

/** Resultado de validar las filas de un archivo (spec 014). */
final class Analysis
{
    /** @var list<array{row: ?int, column: string, message: string}> */
    public array $errors = [];

    /** @var list<array{row: ?int, column: string, message: string}> */
    public array $warnings = [];

    /** @var list<array<string, mixed>> lo que se muestra en la vista previa */
    public array $changes = [];

    /** @var list<array<string, mixed>> lo que se aplica al confirmar */
    public array $rows = [];

    public int $unchanged = 0;

    public function error(?int $row, string $column, string $message): void
    {
        $this->errors[] = ['row' => $row, 'column' => $column, 'message' => $message];
    }

    public function warning(?int $row, string $column, string $message): void
    {
        $this->warnings[] = ['row' => $row, 'column' => $column, 'message' => $message];
    }

    /** @return array{rows: int, create: int, update: int, unchanged: int, errors: int} */
    public function summary(int $rows): array
    {
        $actions = array_count_values(array_column($this->changes, 'action'));

        return [
            'rows' => $rows,
            'create' => $actions['create'] ?? 0,
            'update' => $actions['update'] ?? 0,
            'unchanged' => $this->unchanged,
            'errors' => count(array_unique(array_column($this->errors, 'row'))),
        ];
    }
}

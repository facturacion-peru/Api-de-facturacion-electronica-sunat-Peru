<?php

namespace App\DataTransfer\Imports;

use App\Models\User;

/** Un tipo de dato importable (spec 014): productos o clientes. */
interface RowImport
{
    /** @return list<string> columnas obligatorias del archivo */
    public function requiredColumns(): array;

    /** @return list<string> columnas que se leen */
    public function knownColumns(): array;

    /** @return list<string> columnas de la exportación que la importación ignora */
    public function readOnlyColumns(): array;

    /**
     * Valida las filas sin guardar nada.
     *
     * @param  list<array{int, array<string, string>}>  $records  [número de fila, columna => valor]
     * @param  list<string>  $columns  columnas presentes en el archivo
     */
    public function analyze(array $records, array $columns, string $mode): Analysis;

    /**
     * Antes de aplicar: las altas siguen siendo nuevas (una consulta para
     * todas). Lanza ImportConflict si no. El índice único cubre lo que pase
     * entre esta comprobación y cada alta.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertStillNew(array $rows): void;

    /**
     * Aplica una fila guardada en la vista previa. Lanza ImportConflict si los
     * datos cambiaron desde la vista previa.
     *
     * @param  array<string, mixed>  $row
     * @param  array{created: int, updated: int, entries: int}  $result
     */
    public function apply(array $row, User $actor, array &$result): void;
}

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
     * Aplica una fila guardada en la vista previa. Lanza ImportConflict si los
     * datos cambiaron desde la vista previa.
     *
     * @param  array<string, mixed>  $row
     * @param  array{created: int, updated: int, entries: int}  $result
     */
    public function apply(array $row, User $actor, array &$result): void;
}

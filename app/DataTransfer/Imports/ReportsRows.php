<?php

namespace App\DataTransfer\Imports;

/** Utilidades comunes de los tipos importables (spec 014). */
trait ReportsRows
{
    /**
     * Pasa los errores de validación a la vista previa, con el nombre de la columna.
     *
     * @param  array<string, list<string>>  $messages
     * @param  array<string, string>  $fields  campo => columna
     */
    private function collect(Analysis $analysis, int $row, array $messages, array $fields): bool
    {
        foreach ($messages as $field => $fieldMessages) {
            $analysis->error($row, $fields[$field] ?? $field, $fieldMessages[0]);
        }

        return $messages !== [];
    }

    /**
     * Claves repetidas dentro del archivo => sus filas.
     *
     * @param  array<int, string>  $keys  número de fila => clave ('' se ignora)
     * @return array<string, list<int>>
     */
    private function repeated(array $keys): array
    {
        $rows = [];
        foreach ($keys as $row => $key) {
            if ($key !== '') {
                $rows[$key][] = $row;
            }
        }

        return array_filter($rows, fn (array $list) => count($list) > 1);
    }

    /** @param  list<int>  $rows */
    private function listRows(array $rows): string
    {
        $last = array_pop($rows);

        return $rows === [] ? (string) $last : implode(', ', $rows).' y '.$last;
    }
}

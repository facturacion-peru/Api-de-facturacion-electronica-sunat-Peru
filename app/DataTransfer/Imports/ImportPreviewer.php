<?php

namespace App\DataTransfer\Imports;

use App\DataTransfer\Spreadsheet;
use App\DataTransfer\UnreadableFile;
use App\Models\ImportPreview;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Primer paso de una importación (spec 014): lee el archivo, valida cada
 * fila y guarda la vista previa. No modifica el catálogo; el archivo no se
 * guarda, solo sus filas ya normalizadas.
 */
class ImportPreviewer
{
    public const MAX_ROWS = 2000;

    public function __construct(private ImportKinds $kinds) {}

    public function preview(string $kind, string $mode, UploadedFile $file, User $user): ImportPreview
    {
        $import = $this->kinds->for($kind);
        [$columns, $records] = $this->read($file->getRealPath());

        foreach ($import->requiredColumns() as $column) {
            if (! in_array($column, $columns, true)) {
                $this->fail("Falta la columna «{$column}». Usa la plantilla.");
            }
        }
        if ($records === []) {
            $this->fail('El archivo no tiene filas para importar.');
        }

        $analysis = $import->analyze($records, $columns, $mode);
        foreach ($columns as $column) {
            if (in_array($column, $import->readOnlyColumns(), true)) {
                $analysis->warning(null, $column, 'Columna de solo lectura: se ignora.');
            } elseif ($column !== '' && ! in_array($column, $import->knownColumns(), true)) {
                $analysis->warning(null, $column, 'Columna desconocida: se ignora.');
            }
        }

        $order = array_flip($columns);
        usort($analysis->errors, fn ($a, $b) => [$a['row'], $order[$a['column']] ?? 99] <=> [$b['row'], $order[$b['column']] ?? 99]);
        usort($analysis->warnings, fn ($a, $b) => [$a['row'] ?? 0, $order[$a['column']] ?? 99] <=> [$b['row'] ?? 0, $order[$b['column']] ?? 99]);

        return ImportPreview::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'mode' => $mode,
            'rows' => $analysis->errors === [] ? $analysis->rows : [],
            'summary' => $analysis->summary(count($records)),
            'errors' => $analysis->errors,
            'warnings' => $analysis->warnings,
            'changes' => $analysis->changes,
            'expires_at' => now()->addMinutes(ImportPreview::TTL_MINUTES),
        ]);
    }

    /**
     * Encabezados normalizados y filas como columna => valor.
     *
     * @return array{list<string>, list<array{int, array<string, string>}>}
     */
    private function read(string $path): array
    {
        $columns = null;
        $records = [];

        try {
            foreach (Spreadsheet::rows($path) as [$row, $cells]) {
                if ($columns === null) {
                    $columns = array_map(fn (string $header) => Str::of($header)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString(), $cells);

                    continue;
                }

                if (count($records) === self::MAX_ROWS) {
                    $this->fail('El archivo tiene más de 2 000 filas. Divídelo en varios archivos.');
                }

                $values = [];
                foreach ($columns as $index => $column) {
                    if ($column !== '' && ! array_key_exists($column, $values)) {
                        $values[$column] = $cells[$index] ?? '';
                    }
                }
                $records[] = [$row, $values];
            }
        } catch (UnreadableFile $e) {
            $this->fail($e->getMessage());
        }

        if ($columns === null) {
            $this->fail('El archivo no tiene filas para importar.');
        }

        return [$columns, $records];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

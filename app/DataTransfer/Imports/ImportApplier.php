<?php

namespace App\DataTransfer\Imports;

use App\Audit\AuditLogger;
use App\Models\ImportPreview;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Segundo paso de una importación (spec 014): aplica la vista previa en una
 * sola transacción, todo o nada (A-71). Cada fila pasa por los servicios de
 * siempre, que auditan como si fuera manual. Si los datos cambiaron desde la
 * vista previa, no se aplica nada (409).
 */
class ImportApplier
{
    public function __construct(
        private ImportKinds $kinds,
        private AuditLogger $audit,
    ) {}

    /** @return array{created: int, updated: int, entries: int} */
    public function confirm(ImportPreview $preview, User $user): array
    {
        if ($preview->errors !== []) {
            abort(422, 'La vista previa tiene errores: corrige el archivo y vuelve a subirlo.');
        }

        $import = $this->kinds->for($preview->kind);

        try {
            return DB::transaction(function () use ($preview, $user, $import) {
                // Bloqueo: dos confirmaciones simultáneas no aplican dos veces.
                $locked = ImportPreview::query()->whereKey($preview->id)->lockForUpdate()->firstOrFail();
                if ($locked->confirmed_at !== null) {
                    abort(410, 'Esta importación ya se confirmó.');
                }
                if ($locked->isExpired()) {
                    abort(410, 'La vista previa caducó. Vuelve a subir el archivo.');
                }
                $locked->update(['confirmed_at' => now()]);

                $import->assertStillNew($locked->rows);
                $result = ['created' => 0, 'updated' => 0, 'entries' => 0];
                foreach ($locked->rows as $row) {
                    $import->apply($row, $user, $result);
                }

                // Sin modelo auditado: auditable_id es entero y la vista previa usa UUID.
                $this->audit->record('import.confirmed', changes: [
                    'preview' => $locked->id,
                    'kind' => $locked->kind,
                    'mode' => $locked->mode,
                    'rows' => $locked->summary['rows'] ?? count($locked->rows),
                    ...$result,
                ], actor: $user);

                return $result;
            });
        } catch (ImportConflict|UniqueConstraintViolationException) {
            $what = $preview->kind === 'products' ? 'Los productos' : 'Los clientes';
            abort(409, "{$what} cambiaron desde la vista previa: vuelve a subir el archivo para revisarla de nuevo.");
        }
    }
}

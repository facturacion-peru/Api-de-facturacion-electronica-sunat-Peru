<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Series de comprobantes y su correlativo (HU-3, A-21). */
class SeriesService
{
    public function __construct(private AuditLogger $audit) {}

    /** Alta en el establecimiento principal (A-07), continuando la numeración anterior. */
    public function create(Company $company, DocumentType $type, string $code, int $lastNumber, User $actor): Series
    {
        if ($type === DocumentType::Invoice && ! $company->tax_regime->canIssueInvoices()) {
            throw ValidationException::withMessages(['document_type' => 'Las empresas del Nuevo RUS no emiten facturas.']);
        }

        $series = Series::create([
            'company_id' => $company->id,
            'establishment_id' => Establishment::where('is_main', true)->value('id'),
            'document_type' => $type,
            'code' => $code,
            'last_number' => $lastNumber,
            'active' => true,
        ]);

        $this->audit->record('series.created', $series, [
            'code' => $series->code,
            'document_type' => $type->value,
            'last_number' => $lastNumber,
        ], actor: $actor);

        return $series;
    }

    public function setActive(Series $series, bool $active, User $actor): Series
    {
        if ($series->active !== $active) {
            $series->update(['active' => $active]);
            $this->audit->record($active ? 'series.activated' : 'series.deactivated', $series, ['code' => $series->code], actor: $actor);
        }

        return $series;
    }

    /**
     * Siguiente correlativo, bloqueando la fila (spec 005). Debe llamarse
     * dentro de la transacción de la emisión: si la emisión falla, el número
     * no se consume (RF-023).
     */
    public function nextNumber(Series $series): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('nextNumber() debe ejecutarse dentro de la transacción de la emisión.');
        }

        $locked = Series::whereKey($series->id)->lockForUpdate()->firstOrFail();
        $locked->last_number++;
        $locked->save();

        return $locked->last_number;
    }
}

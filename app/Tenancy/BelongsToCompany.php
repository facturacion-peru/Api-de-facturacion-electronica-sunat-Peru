<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca un modelo como dato de empresa (principio VIII).
 *
 * - Lectura: siempre filtrada por la empresa del contexto; sin contexto lanza
 *   TenantContextMissing. `withoutTenancy()` es la única salida y solo debe
 *   usarse en código de plataforma.
 * - Escritura con contexto: rellena company_id y rechaza uno ajeno.
 * - Escritura sin contexto: solo con company_id explícito (consola, plataforma).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model) {
            $tenant = app(TenantContext::class);

            if ($model->company_id === null) {
                if (! $tenant->has()) {
                    throw new TenantContextMissing($model::class);
                }

                $model->company_id = $tenant->id();
            }

            static::guardTenant($model, $tenant);
        });

        static::updating(function (Model $model) {
            static::guardTenant($model, app(TenantContext::class));
        });
    }

    /** @return Builder<static> */
    public static function withoutTenancy(): Builder
    {
        return static::withoutGlobalScope(CompanyScope::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    private static function guardTenant(Model $model, TenantContext $tenant): void
    {
        if ($tenant->has() && (int) $model->company_id !== $tenant->id()) {
            throw new TenantMismatch($model::class);
        }
    }
}

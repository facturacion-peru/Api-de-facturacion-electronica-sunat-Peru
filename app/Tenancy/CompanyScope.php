<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Limita toda consulta a la empresa del contexto; sin contexto, falla. */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if (! $tenant->has()) {
            throw new TenantContextMissing($model::class);
        }

        $builder->where($model->qualifyColumn('company_id'), $tenant->id());
    }
}

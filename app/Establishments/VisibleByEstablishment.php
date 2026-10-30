<?php

namespace App\Establishments;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Para modelos con `establishment_id`: limita una consulta a los locales que
 * el usuario puede leer (spec 017, CE-002).
 */
trait VisibleByEstablishment
{
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('establishment_id'), app(EstablishmentAccess::class)->readable($user));
    }
}

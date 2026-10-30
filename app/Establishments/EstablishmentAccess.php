<?php

namespace App\Establishments;

use App\Enums\CompanyRole;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * En qué locales trabaja y qué locales ve cada usuario (spec 017, A-87).
 *
 * El administrador de empresa, en todos; el vendedor, en los asignados. Para
 * trabajar (vender, entradas, traslados) el local debe estar activo; para
 * leer, también valen los inactivos (su historial). Un local de otra empresa
 * no existe para el scope de empresa: recibe el mismo rechazo.
 */
class EstablishmentAccess
{
    /** @return Collection<int, Establishment> Locales activos donde puede trabajar. */
    public function allowed(User $user): Collection
    {
        return $this->query($user)->active()->orderBy('code')->get();
    }

    /** @return Collection<int, Establishment> Los permitidos, listos para EstablishmentResource. */
    public function allowedForDisplay(User $user): Collection
    {
        return $this->query($user)->active()->with('district')->withExists('salesDocuments as code_locked')->orderBy('code')->get();
    }

    /** @return list<int> Ids de los locales que puede leer, incluidos los inactivos. */
    public function readable(User $user): array
    {
        return $this->query($user)->orderBy('code')->pluck('id')->all();
    }

    /**
     * Local donde se hace una escritura. Sin id, el único permitido.
     *
     * @throws ValidationException
     */
    public function resolve(User $user, ?int $id, string $field = 'establishment_id'): Establishment
    {
        $allowed = $this->allowed($user);

        if ($id !== null) {
            return $allowed->firstWhere('id', $id)
                ?? throw ValidationException::withMessages([$field => 'No puedes trabajar en ese local.']);
        }

        return match ($allowed->count()) {
            0 => throw ValidationException::withMessages([$field => 'No tienes un local asignado. Pide al administrador que te asigne uno.']),
            1 => $allowed->first(),
            default => throw ValidationException::withMessages([$field => 'Elige el local.']),
        };
    }

    /**
     * Locales de una lectura: el pedido, o «Todos» los que puede leer.
     *
     * @return list<int>
     *
     * @throws ValidationException
     */
    public function filter(User $user, ?int $id, string $field = 'establishment_id'): array
    {
        $readable = $this->readable($user);

        if ($id === null) {
            return $readable;
        }

        if (! in_array($id, $readable, true)) {
            throw ValidationException::withMessages([$field => 'No puedes ver ese local.']);
        }

        return [$id];
    }

    /** @return Builder<Establishment> */
    private function query(User $user): Builder
    {
        $query = Establishment::query();

        if (! $user->hasCompanyRole(CompanyRole::CompanyAdmin)) {
            $query->whereIn('id', $user->establishments()->select('establishments.id'));
        }

        return $query;
    }
}

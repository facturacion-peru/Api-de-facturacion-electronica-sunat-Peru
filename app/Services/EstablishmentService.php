<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Locales de la empresa y sus vendedores (spec 017, HU-1, A-87, A-89). */
class EstablishmentService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param  array{code: string, name: string, address: string, ubigeo: string}  $data */
    public function create(Company $company, array $data, User $actor): Establishment
    {
        $establishment = Establishment::create([...$data, 'company_id' => $company->id, 'is_main' => false, 'active' => true]);

        $this->audit->record('establishment.created', $establishment, [
            'code' => $establishment->code,
            'name' => $establishment->name,
        ], actor: $actor);

        return $establishment;
    }

    /** @param  array{code?: string, name?: string, address?: string, ubigeo?: string, active?: bool}  $data */
    public function update(Establishment $establishment, array $data, User $actor): Establishment
    {
        return DB::transaction(function () use ($establishment, $data, $actor) {
            $establishment = Establishment::whereKey($establishment->id)->lockForUpdate()->firstOrFail();

            if (isset($data['code']) && $data['code'] !== $establishment->code && $establishment->salesDocuments()->exists()) {
                throw ValidationException::withMessages(['code' => 'El código no se puede cambiar: el local ya emitió comprobantes con él.']);
            }

            if (array_key_exists('active', $data) && ! $data['active'] && $establishment->is_main) {
                throw ValidationException::withMessages(['active' => 'El local principal no se puede desactivar.']);
            }

            $before = $establishment->only(['code', 'name', 'address', 'ubigeo']);
            $wasActive = $establishment->active;
            $establishment->update($data);

            $changes = array_diff_assoc($establishment->only(['code', 'name', 'address', 'ubigeo']), $before);
            if ($changes !== []) {
                $this->audit->record('establishment.updated', $establishment, ['from' => array_intersect_key($before, $changes), 'to' => $changes], actor: $actor);
            }

            if ($establishment->active !== $wasActive) {
                $this->audit->record($establishment->active ? 'establishment.activated' : 'establishment.deactivated', $establishment, [
                    'code' => $establishment->code,
                ], actor: $actor);
            }

            return $establishment;
        });
    }

    /**
     * Reemplaza los locales de un vendedor. El administrador de empresa
     * trabaja en todos sin asignación (A-87).
     *
     * @param  list<int>  $ids
     */
    public function assign(User $user, array $ids, User $actor): User
    {
        if (! $user->hasCompanyRole(CompanyRole::Seller)) {
            throw ValidationException::withMessages(['user' => 'Solo se asignan locales a vendedores: el administrador trabaja en todos.']);
        }

        return DB::transaction(function () use ($user, $ids, $actor) {
            $codes = fn () => $user->establishments()->orderBy('code')->pluck('code')->all();
            $before = $codes();

            $user->establishments()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['company_id' => $user->membership->company_id]])->all());

            $after = $codes();
            if ($after !== $before) {
                $this->audit->record('user.establishments_changed', $user, ['from' => $before, 'to' => $after], actor: $actor);
            }

            return $user;
        });
    }
}

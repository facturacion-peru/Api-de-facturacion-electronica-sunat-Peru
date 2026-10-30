<?php

namespace App\Http\Resources;

use App\Establishments\EstablishmentAccess;
use App\Models\Establishment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Sesión de un usuario de empresa: quién es, su empresa y su rol. Incluye el
 * token solo al iniciar sesión o aceptar una invitación.
 *
 * @mixin User
 */
class SessionResource extends ApiResource
{
    public function __construct(User $user, private ?string $token = null)
    {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $membership = $this->membership;
        $company = $membership?->company;

        return [
            'token' => $this->when($this->token !== null, $this->token),
            'expires_at' => $this->when(
                $this->token !== null,
                fn () => now()->addMinutes((int) config('sanctum.expiration'))->toIso8601String(),
            ),
            'user' => UserResource::make($this->resource),
            'platform_admin' => $this->is_platform_admin,
            'company' => $company ? [
                'id' => $company->id,
                'ruc' => $company->ruc,
                'razon_social' => $company->razon_social,
                'nombre_comercial' => $company->nombre_comercial,
                'active_establishments_count' => Establishment::withoutTenancy()->where('company_id', $company->id)->where('active', true)->count(),
            ] : null,
            'role' => $membership?->role->value,
            // Locales donde puede trabajar (spec 017): la app decide con esto y
            // la cuenta de arriba si muestra el selector (RF-010).
            'establishments' => $company
                ? app(TenantContext::class)->run($company, fn () => EstablishmentResource::collection(app(EstablishmentAccess::class)->allowedForDisplay($this->resource)))
                : [],
        ];
    }
}

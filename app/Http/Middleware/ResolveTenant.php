<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa del usuario autenticado como contexto (principio VIII).
 * Va siempre después de auth:sanctum.
 *
 * Usuario, pertenencia o empresa inactivos: 401 y se revocan sus tokens, de
 * modo que una desactivación corta las sesiones abiertas (RF-013, CE-004).
 */
class ResolveTenant
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @param  string|null  $mode  'allow-platform': deja pasar al administrador de la
     *                             plataforma sin contexto de empresa (solo /auth/me, spec 006)
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isPlatformAdmin()) {
            if ($mode !== 'allow-platform') {
                throw new AuthorizationException('El administrador de la plataforma no opera empresas.');
            }

            if (! $user->active) {
                $user->tokens()->delete();

                throw new AuthenticationException;
            }

            return $next($request);
        }

        $membership = $user->membership()->with('company')->first();

        if ($membership === null) {
            throw new AuthorizationException;
        }

        if (! $user->active || ! $membership->active || ! $membership->company->active) {
            $user->tokens()->delete();

            throw new AuthenticationException;
        }

        $this->tenant->set($membership->company);
        $user->setRelation('membership', $membership);

        return $next($request);
    }
}

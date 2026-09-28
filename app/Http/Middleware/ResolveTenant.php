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

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isPlatformAdmin()) {
            throw new AuthorizationException('El administrador de la plataforma no opera empresas.');
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

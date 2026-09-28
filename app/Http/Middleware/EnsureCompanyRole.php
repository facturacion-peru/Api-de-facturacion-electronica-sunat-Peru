<?php

namespace App\Http\Middleware;

use App\Enums\CompanyRole;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: `role:company_admin`. Va después de `tenant`. */
class EnsureCompanyRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => CompanyRole::from($role), $roles);

        if (! in_array($request->user()->membership?->role, $allowed, true)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}

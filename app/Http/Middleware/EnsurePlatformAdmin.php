<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rutas /platform/*: solo administradores de la plataforma activos. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->isPlatformAdmin()) {
            throw new AuthorizationException;
        }

        if (! $user->active) {
            $user->tokens()->delete();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}

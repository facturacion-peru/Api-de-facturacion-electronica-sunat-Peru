<?php

namespace App\Providers;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /** Peticiones por minuto e IP a las rutas públicas de autenticación. */
    public const PUBLIC_AUTH_PER_MINUTE = 30;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una instancia por petición o trabajo de cola.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rutas públicas de autenticación (principio IV). Holgado porque una
        // oficina comparte IP pública; la defensa contra fuerza bruta es el
        // bloqueo por cuenta (5 fallos en 15 min por correo + IP, RF-012).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(self::PUBLIC_AUTH_PER_MINUTE)->by($request->ip()));

        // User no tiene scope de empresa: {user} se resuelve solo entre los
        // miembros de la empresa del contexto (fuera de uno, siempre 404).
        Route::bind('user', fn (string $value) => User::query()
            ->whereKey($value)
            ->whereHas('membership', fn ($query) => $query->where('company_id', app(TenantContext::class)->id()))
            ->with('membership')
            ->firstOrFail());

        // Política de contraseñas para administradores, invitaciones y recuperación.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());
    }
}

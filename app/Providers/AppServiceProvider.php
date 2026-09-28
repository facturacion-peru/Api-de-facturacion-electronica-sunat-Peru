<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
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
        // Rutas públicas de autenticación (principio IV). El bloqueo por
        // intentos fallidos de login por correo + IP va aparte (RF-012).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Política de contraseñas para administradores, invitaciones y recuperación.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());
    }
}

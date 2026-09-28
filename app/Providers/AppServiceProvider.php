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

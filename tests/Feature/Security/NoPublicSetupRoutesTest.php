<?php

use Illuminate\Support\Facades\Route;

/*
 * T093 · RF-050 y constitución 1.1.1: las únicas rutas públicas de la API son
 * los flujos de autenticación, y ninguna ejecuta migraciones ni seeders.
 */

it('toda ruta pública de la API es un flujo de autenticación', function () {
    $allowed = [
        'POST api/v1/auth/login',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/reset-password',
        'GET api/v1/invitations/{token}',
        'POST api/v1/invitations/{token}/accept',
    ];

    $public = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->reject(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => "{$method} {$route->uri()}"))
        ->sort()->values()->all();

    expect($public)->toEqualCanonicalizing($allowed);
});

it('las rutas públicas tienen límite de intentos', function () {
    collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->reject(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->each(fn ($route) => expect($route->gatherMiddleware())->toContain('throttle:auth'));
});

it('no existe ninguna ruta de setup, migraciones ni seeders', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map->uri()->implode(' ');

    expect($uris)->not->toContain('setup')
        ->not->toContain('migrate')
        ->not->toContain('seed');
});

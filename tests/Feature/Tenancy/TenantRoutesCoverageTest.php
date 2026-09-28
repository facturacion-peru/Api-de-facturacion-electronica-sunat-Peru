<?php

use Illuminate\Support\Facades\Route;

/*
 * T091 · Cada ruta de empresa tiene su caso de acceso cruzado (RF-033,
 * CE-002). Añadir una ruta sin declararla en TenantRoutes.php rompe la suite.
 */

it('todas las rutas de empresa están en el inventario de acceso cruzado', function () {
    $declared = array_keys(require __DIR__.'/TenantRoutes.php');

    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('tenant', $route->gatherMiddleware(), true))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => "{$method} {$route->uri()}"))
        ->sort()->values()->all();

    expect(array_values(array_diff($actual, $declared)))->toBe([], 'Rutas de empresa sin caso de acceso cruzado')
        ->and(array_values(array_diff($declared, $actual)))->toBe([], 'Rutas declaradas que ya no existen');
});

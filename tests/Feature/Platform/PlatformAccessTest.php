<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * T002 · RF-001: todas las rutas de plataforma, sacadas del router, exigen
 * sesión (401) y el rol de administrador de la plataforma (403). Una ruta
 * nueva sin `platform.admin` rompe esta prueba.
 */

function platformRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/platform/'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri())]))
        ->values()->all();
}

it('hay rutas de plataforma que revisar', function () {
    expect(count(platformRoutes()))->toBeGreaterThanOrEqual(6);
});

it('sin sesión, toda ruta de plataforma responde 401', function () {
    foreach (platformRoutes() as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

it('un usuario de empresa recibe 403 en toda ruta de plataforma', function (CompanyRole $role) {
    $company = Company::factory()->withMainEstablishment()->create();
    $user = User::factory()->forCompany($company, $role)->create();
    $token = $user->createToken('t')->plainTextToken;

    foreach (platformRoutes() as [$method, $uri]) {
        app('auth')->forgetGuards();
        expect($this->withToken($token)->json($method, $uri)->status())->toBe(403, "{$method} {$uri}");
    }
})->with([CompanyRole::CompanyAdmin, CompanyRole::Seller]);

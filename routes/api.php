<?php

use App\Http\Controllers\Api\UbigeoController;
use Illuminate\Support\Facades\Route;

/*
| Tres grupos, según docs/specs/001-empresa-usuarios-aislamiento/plan.md:
|
| - Público: solo flujos de autenticación con límite de intentos (principio IV).
| - Empresa: auth:sanctum + tenant. Toda ruta nueva aquí necesita su caso en
|   tests/Feature/Tenancy/CrossTenantAccessTest.php (principio VIII).
| - Plataforma: auth:sanctum + platform.admin.
*/

Route::prefix('v1')->group(function () {
    // Público
    Route::middleware('throttle:auth')->group(function () {
        //
    });

    // Empresa
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::prefix('ubigeos')->group(function () {
            Route::get('/regiones', [UbigeoController::class, 'getRegiones']);
            Route::get('/provincias', [UbigeoController::class, 'getProvincias']);
            Route::get('/distritos', [UbigeoController::class, 'getDistritos']);
            Route::get('/search', [UbigeoController::class, 'searchUbigeo']);
            Route::get('/{id}', [UbigeoController::class, 'getUbigeoById']);
        });
    });

    // Plataforma
    Route::middleware(['auth:sanctum', 'platform.admin'])->prefix('platform')->group(function () {
        //
    });
});

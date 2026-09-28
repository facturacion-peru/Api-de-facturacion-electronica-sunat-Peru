<?php

use App\Http\Controllers\Api\UbigeoController;
use Illuminate\Support\Facades\Route;

// Las rutas de empresa, plataforma y autenticación se añaden con la spec 001
// (docs/specs/001-empresa-usuarios-aislamiento/plan.md).

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::prefix('ubigeos')->group(function () {
        Route::get('/regiones', [UbigeoController::class, 'getRegiones']);
        Route::get('/provincias', [UbigeoController::class, 'getProvincias']);
        Route::get('/distritos', [UbigeoController::class, 'getDistritos']);
        Route::get('/search', [UbigeoController::class, 'searchUbigeo']);
        Route::get('/{id}', [UbigeoController::class, 'getUbigeoById']);
    });
});

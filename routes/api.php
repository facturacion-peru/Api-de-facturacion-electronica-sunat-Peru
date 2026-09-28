<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Company\InvitationController;
use App\Http\Controllers\Api\Platform\CompanyController as PlatformCompanyController;
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
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('/auth/reset-password', [PasswordResetController::class, 'reset']);
        Route::get('/invitations/{token}', [InvitationAcceptanceController::class, 'show']);
        Route::post('/invitations/{token}/accept', [InvitationAcceptanceController::class, 'accept']);
    });

    // Cualquier usuario autenticado: cerrar su propia sesión.
    Route::middleware('auth:sanctum')->post('/auth/logout', [AuthController::class, 'logout']);

    // Empresa
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::middleware('role:company_admin')->group(function () {
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend']);
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
        });

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
        Route::post('/companies', [PlatformCompanyController::class, 'store']);
    });
});

<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Company\AuditLogController;
use App\Http\Controllers\Api\Company\CompanyController;
use App\Http\Controllers\Api\Company\InvitationController;
use App\Http\Controllers\Api\Company\UserController;
use App\Http\Controllers\Api\Inventory\AlertController;
use App\Http\Controllers\Api\Inventory\CatalogController;
use App\Http\Controllers\Api\Inventory\ProductController;
use App\Http\Controllers\Api\Inventory\StockController;
use App\Http\Controllers\Api\Platform\CompanyController as PlatformCompanyController;
use App\Http\Controllers\Api\Sales\CustomerController;
use App\Http\Controllers\Api\Sales\SalesDocumentController;
use App\Http\Controllers\Api\Sales\TicketController;
use App\Http\Controllers\Api\Sunat\SeriesController;
use App\Http\Controllers\Api\Sunat\SunatController;
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
        Route::get('/company', [CompanyController::class, 'show']);

        Route::get('/catalogs/inventory', [CatalogController::class, 'inventory']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/products/{product}/lots', [StockController::class, 'lots']);

        Route::get('/sunat/status', [SunatController::class, 'status']);
        Route::get('/series', [SeriesController::class, 'index']);
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
        Route::get('/sales-documents', [SalesDocumentController::class, 'index']);
        Route::post('/sales-documents', [SalesDocumentController::class, 'store']);
        Route::get('/sales-documents/{salesDocument}', [SalesDocumentController::class, 'show']);
        Route::post('/sales-documents/{salesDocument}/retry', [SalesDocumentController::class, 'retry']);
        Route::get('/sales-documents/{salesDocument}/pdf', [SalesDocumentController::class, 'pdf']);
        Route::get('/sales-documents/{salesDocument}/xml', [SalesDocumentController::class, 'xml']);
        Route::get('/sales-documents/{salesDocument}/cdr', [SalesDocumentController::class, 'cdr']);

        Route::middleware('role:company_admin')->group(function () {
            Route::patch('/company', [CompanyController::class, 'update']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::patch('/products/{product}', [ProductController::class, 'update']);
            Route::post('/products/{product}/entries', [StockController::class, 'storeEntry']);
            Route::post('/lots/{lot}/adjustments', [StockController::class, 'adjust']);
            Route::post('/movements/{movement}/reverse', [StockController::class, 'reverse']);
            Route::get('/products/{product}/movements', [StockController::class, 'movements']);
            Route::get('/inventory/alerts', [AlertController::class, 'index']);
            Route::post('/tickets/{ticket}/void', [TicketController::class, 'void']);
            Route::get('/sunat/settings', [SunatController::class, 'settings']);
            Route::put('/sunat/credentials', [SunatController::class, 'updateCredentials']);
            Route::post('/sunat/certificate', [SunatController::class, 'uploadCertificate']);
            Route::post('/sunat/validate', [SunatController::class, 'validate']);
            Route::post('/series', [SeriesController::class, 'store']);
            Route::patch('/series/{series}', [SeriesController::class, 'update']);
            Route::post('/company/logo', [CompanyController::class, 'updateLogo']);

            Route::get('/users', [UserController::class, 'index']);
            Route::patch('/users/{user}', [UserController::class, 'update']);

            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend']);
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);

            Route::get('/audit-logs', [AuditLogController::class, 'index']);
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
        Route::get('/companies', [PlatformCompanyController::class, 'index']);
        Route::post('/companies', [PlatformCompanyController::class, 'store']);
        Route::get('/companies/{company}', [PlatformCompanyController::class, 'show']);
        Route::patch('/companies/{company}', [PlatformCompanyController::class, 'update']);
        Route::post('/companies/{company}/activate', [PlatformCompanyController::class, 'activate']);
        Route::post('/companies/{company}/deactivate', [PlatformCompanyController::class, 'deactivate']);
    });
});

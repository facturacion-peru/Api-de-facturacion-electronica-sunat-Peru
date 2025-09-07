<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\BoletaController;
use App\Http\Controllers\Api\DailySummaryController;
use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\DebitNoteController;
use App\Http\Controllers\Api\RetentionController;
use App\Http\Controllers\Api\VoidedDocumentController;
use App\Http\Controllers\Api\DispatchGuideController;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\Api\CompanyConfigController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SetupController;

// ========================
// RUTAS DE AUTENTICACIÓN Y SETUP (SIN MIDDLEWARE)
// ========================

// Información del sistema (público)
Route::get('/system/info', [AuthController::class, 'systemInfo']);

// Inicialización del sistema (solo si no hay usuarios)
Route::post('/auth/initialize', [AuthController::class, 'initialize']);

// Login
Route::post('/auth/login', [AuthController::class, 'login']);

// ========================
// RUTAS PROTEGIDAS
// ========================
Route::middleware('auth:sanctum')->group(function () {
    
    // Autenticación
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/create-user', [AuthController::class, 'createUser']);
    
    // Setup del sistema
    Route::prefix('setup')->group(function () {
        Route::post('/migrate', [SetupController::class, 'migrate']);
        Route::post('/seed', [SetupController::class, 'seed']);
        Route::get('/status', [SetupController::class, 'status']);
        Route::post('/complete', [SetupController::class, 'setup']);
        Route::post('/configure-sunat', [SetupController::class, 'configureSunat']);
    });
    
    // Usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

// Rutas de la API SUNAT
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    
    // PDF Formatos
    Route::prefix('pdf')->group(function () {
        Route::get('/formats', [PdfController::class, 'getAvailableFormats']);
    });
    
    // Facturas
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{id}', [InvoiceController::class, 'show']);
        Route::post('/{id}/send-sunat', [InvoiceController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [InvoiceController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [InvoiceController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [InvoiceController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [InvoiceController::class, 'generatePdf']);
    });
    
    // Boletas
    Route::prefix('boletas')->group(function () {
        Route::get('/', [BoletaController::class, 'index']);
        Route::post('/', [BoletaController::class, 'store']);
        Route::get('/{id}', [BoletaController::class, 'show']);
        Route::post('/{id}/send-sunat', [BoletaController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [BoletaController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [BoletaController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [BoletaController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [BoletaController::class, 'generatePdf']);
        
        // Funciones de resumen diario desde boletas
        Route::get('/pending-for-summary', [BoletaController::class, 'getBoletsasPendingForSummary']);
        Route::post('/create-daily-summary', [BoletaController::class, 'createDailySummaryFromDate']);
        Route::post('/summary/{id}/send-sunat', [BoletaController::class, 'sendSummaryToSunat']);
        Route::post('/summary/{id}/check-status', [BoletaController::class, 'checkSummaryStatus']);
    });

    // Resúmenes Diarios
    Route::prefix('daily-summaries')->group(function () {
        Route::get('/', [DailySummaryController::class, 'index']);                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  
        Route::post('/', [DailySummaryController::class, 'store']);
        Route::get('/{id}', [DailySummaryController::class, 'show']);
        Route::post('/{id}/send-sunat', [DailySummaryController::class, 'sendToSunat']);
        Route::post('/{id}/check-status', [DailySummaryController::class, 'checkStatus']);
        Route::get('/{id}/download-xml', [DailySummaryController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DailySummaryController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DailySummaryController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DailySummaryController::class, 'generatePdf']);
        
        // Funciones de gestión masiva
        Route::get('/pending', [DailySummaryController::class, 'getPendingSummaries']);
        Route::post('/check-all-pending', [DailySummaryController::class, 'checkAllPendingStatus']);
    });

    // Notas de Crédito
    Route::prefix('credit-notes')->group(function () {
        Route::get('/', [CreditNoteController::class, 'index']);
        Route::post('/', [CreditNoteController::class, 'store']);
        Route::get('/{id}', [CreditNoteController::class, 'show']);
        Route::post('/{id}/send-sunat', [CreditNoteController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [CreditNoteController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [CreditNoteController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [CreditNoteController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [CreditNoteController::class, 'generatePdf']);
        
        // Catálogo de motivos
        Route::get('/catalogs/motivos', [CreditNoteController::class, 'getMotivos']);
    });

    // Notas de Débito
    Route::prefix('debit-notes')->group(function () {
        Route::get('/', [DebitNoteController::class, 'index']);
        Route::post('/', [DebitNoteController::class, 'store']);
        Route::get('/{id}', [DebitNoteController::class, 'show']);
        Route::post('/{id}/send-sunat', [DebitNoteController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [DebitNoteController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DebitNoteController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DebitNoteController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DebitNoteController::class, 'generatePdf']);
        
        // Catálogo de motivos
        Route::get('/catalogs/motivos', [DebitNoteController::class, 'getMotivos']);
    });

    // Comprobantes de Retención
    Route::prefix('retentions')->group(function () {
        Route::get('/', [RetentionController::class, 'index']);
        Route::post('/', [RetentionController::class, 'store']);
        Route::get('/{id}', [RetentionController::class, 'show']);
        Route::post('/{id}/send-sunat', [RetentionController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [RetentionController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [RetentionController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [RetentionController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [RetentionController::class, 'generatePdf']);
    });

    // Comunicaciones de Baja
    Route::prefix('voided-documents')->group(function () {
        Route::get('/', [VoidedDocumentController::class, 'index']);
        Route::post('/', [VoidedDocumentController::class, 'store']);
        
        // Obtener documentos disponibles para anular (DEBE IR ANTES de /{id})
        Route::get('/available-documents', [VoidedDocumentController::class, 'getDocumentsForVoiding']);
        
        Route::get('/{id}', [VoidedDocumentController::class, 'show']);
        Route::post('/{id}/send-sunat', [VoidedDocumentController::class, 'sendToSunat']);
        Route::post('/{id}/check-status', [VoidedDocumentController::class, 'checkStatus']);
        Route::get('/{id}/download-xml', [VoidedDocumentController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [VoidedDocumentController::class, 'downloadCdr']);
    });

    // Guías de Remisión
    Route::prefix('dispatch-guides')->group(function () {
        Route::get('/', [DispatchGuideController::class, 'index']);
        Route::post('/', [DispatchGuideController::class, 'store']);
        Route::get('/{id}', [DispatchGuideController::class, 'show']);
        Route::post('/{id}/send-sunat', [DispatchGuideController::class, 'sendToSunat']);
        Route::post('/{id}/check-status', [DispatchGuideController::class, 'checkStatus']);
        Route::get('/{id}/download-xml', [DispatchGuideController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DispatchGuideController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DispatchGuideController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DispatchGuideController::class, 'generatePdf']);
        
        // Catálogos
        Route::get('/catalogs/transfer-reasons', [DispatchGuideController::class, 'getTransferReasons']);
        Route::get('/catalogs/transport-modes', [DispatchGuideController::class, 'getTransportModes']);
    });

    // Configuraciones de Empresas
    Route::prefix('companies/{company_id}/config')->group(function () {
        // Obtener configuración completa o por secciones
        Route::get('/', [CompanyConfigController::class, 'show']);
        Route::get('/{section}', [CompanyConfigController::class, 'getSection']);
        
        // Actualizar configuración por secciones
        Route::put('/{section}', [CompanyConfigController::class, 'updateSection']);
        
        // Validar configuraciones de servicios SUNAT
        Route::get('/validate/services', [CompanyConfigController::class, 'validateServices']);
        
        // Resetear a valores por defecto
        Route::post('/reset', [CompanyConfigController::class, 'resetToDefaults']);
        
        // Migrar empresa al nuevo sistema
        Route::post('/migrate', [CompanyConfigController::class, 'migrateCompany']);
        
        // Limpiar cache
        Route::delete('/cache', [CompanyConfigController::class, 'clearCache']);
    });

    // Configuraciones generales
    Route::prefix('config')->group(function () {
        // Obtener configuraciones por defecto
        Route::get('/defaults', [CompanyConfigController::class, 'getDefaults']);
        
        // Resumen de configuraciones de múltiples empresas
        Route::get('/summary', [CompanyConfigController::class, 'getSummary']);
    });
    
});
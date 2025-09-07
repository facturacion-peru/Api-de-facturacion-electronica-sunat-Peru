<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanyConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CompanyConfigController extends Controller
{
    protected $configService;

    public function __construct(CompanyConfigService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Obtener configuración completa de una empresa
     */
    public function show(Request $request, $companyId): JsonResponse
    {
        try {
            $company = Company::findOrFail($companyId);
            
            $includeCache = $request->boolean('use_cache', true);
            $config = $this->configService->getCompanyConfiguration($company, $includeCache);
            
            return response()->json([
                'success' => true,
                'data' => $config,
                'message' => 'Configuración obtenida correctamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener configuración de una sección específica
     */
    public function getSection(Request $request, $companyId, $section): JsonResponse
    {
        try {
            $validSections = [
                'servicios_sunat',
                'facturacion', 
                'guias_remision',
                'resumenes_diarios',
                'comunicaciones_baja',
                'documentos',
                'archivos',
                'notificaciones',
                'seguridad'
            ];
            
            if (!in_array($section, $validSections)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sección no válida',
                    'available_sections' => $validSections
                ], 400);
            }
            
            $company = Company::findOrFail($companyId);
            $config = $company->getConfig($section, []);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'section' => $section,
                    'config' => $config
                ],
                'message' => "Configuración de {$section} obtenida correctamente"
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sección: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar configuración de una sección específica
     */
    public function updateSection(Request $request, $companyId, $section): JsonResponse
    {
        try {
            $validSections = [
                'servicios_sunat',
                'facturacion',
                'guias_remision', 
                'resumenes_diarios',
                'comunicaciones_baja',
                'documentos',
                'archivos',
                'notificaciones',
                'seguridad'
            ];
            
            if (!in_array($section, $validSections)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sección no válida',
                    'available_sections' => $validSections
                ], 400);
            }
            
            $company = Company::findOrFail($companyId);
            
            // Validar datos de entrada según la sección
            $validatedData = $this->validateSectionData($request, $section);
            
            // Actualizar configuración
            $updated = $this->configService->updateConfiguration($company, $section, $validatedData);
            
            if ($updated) {
                $newConfig = $company->fresh()->getConfig($section, []);
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'section' => $section,
                        'config' => $newConfig
                    ],
                    'message' => "Configuración de {$section} actualizada correctamente"
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo actualizar la configuración'
                ], 500);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar configuración: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Validar estado de configuraciones de servicios SUNAT
     */
    public function validateServices($companyId): JsonResponse
    {
        try {
            $company = Company::findOrFail($companyId);
            
            $validations = $this->configService->validateSunatServices($company);
            $status = $this->configService->getConfigurationStatus($company);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'validations' => $validations,
                    'status' => $status,
                    'company' => [
                        'id' => $company->id,
                        'ruc' => $company->ruc,
                        'razon_social' => $company->razon_social,
                        'modo_produccion' => $company->modo_produccion
                    ]
                ],
                'message' => 'Validación completada'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al validar servicios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resetear configuración a valores por defecto
     */
    public function resetToDefaults(Request $request, $companyId): JsonResponse
    {
        try {
            $company = Company::findOrFail($companyId);
            
            $section = $request->input('section');
            
            $reset = $this->configService->resetToDefaults($company, $section);
            
            if ($reset) {
                $message = $section 
                    ? "Sección {$section} reseteada a valores por defecto"
                    : "Todas las configuraciones reseteadas a valores por defecto";
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'section' => $section,
                        'reset_all' => !$section
                    ],
                    'message' => $message
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo resetear la configuración'
                ], 500);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al resetear configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Migrar empresa al nuevo sistema de configuraciones
     */
    public function migrateCompany($companyId): JsonResponse
    {
        try {
            $company = Company::findOrFail($companyId);
            
            $migrated = $this->configService->migrateCompany($company);
            
            if ($migrated) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'company_id' => $company->id,
                        'ruc' => $company->ruc,
                        'migrated_at' => now()
                    ],
                    'message' => 'Empresa migrada exitosamente al nuevo sistema de configuraciones'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo migrar la empresa'
                ], 500);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al migrar empresa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener configuraciones por defecto
     */
    public function getDefaults(): JsonResponse
    {
        try {
            // Crear una instancia temporal para obtener defaults
            $company = new Company();
            $defaults = $company->getDefaultConfigurations();
            
            return response()->json([
                'success' => true,
                'data' => $defaults,
                'message' => 'Configuraciones por defecto obtenidas correctamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener configuraciones por defecto: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de configuración para múltiples empresas
     */
    public function getSummary(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'company_ids' => 'array',
                'company_ids.*' => 'integer|exists:companies,id'
            ]);
            
            $companyIds = $request->input('company_ids', []);
            $query = Company::query();
            
            if (!empty($companyIds)) {
                $query->whereIn('id', $companyIds);
            }
            
            $companies = $query->get();
            $summary = [];
            
            foreach ($companies as $company) {
                $status = $this->configService->getConfigurationStatus($company);
                $summary[] = [
                    'company' => [
                        'id' => $company->id,
                        'ruc' => $company->ruc,
                        'razon_social' => $company->razon_social,
                        'modo_produccion' => $company->modo_produccion,
                        'activo' => $company->activo,
                    ],
                    'config_status' => $status,
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $summary,
                'total' => count($summary),
                'message' => 'Resumen de configuraciones obtenido correctamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Limpiar cache de configuración
     */
    public function clearCache($companyId): JsonResponse
    {
        try {
            $company = Company::findOrFail($companyId);
            
            $this->configService->clearCompanyCache($company);
            
            return response()->json([
                'success' => true,
                'message' => 'Cache de configuración limpiado correctamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al limpiar cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar datos de entrada según la sección
     */
    private function validateSectionData(Request $request, string $section): array
    {
        switch ($section) {
            case 'facturacion':
                return $request->validate([
                    'igv_porcentaje' => 'sometimes|numeric|min:0|max:50',
                    'isc_porcentaje' => 'sometimes|numeric|min:0|max:50',
                    'icbper_monto' => 'sometimes|numeric|min:0',
                    'ivap_porcentaje' => 'sometimes|numeric|min:0|max:50',
                    'redondeo_automatico' => 'sometimes|boolean',
                    'decimales_precio_unitario' => 'sometimes|integer|min:2|max:10',
                    'decimales_cantidad' => 'sometimes|integer|min:2|max:10',
                    'incluir_leyenda_monto' => 'sometimes|boolean',
                    'validar_ruc_cliente' => 'sometimes|boolean',
                    'permitir_precio_cero' => 'sometimes|boolean',
                ]);
                
            case 'guias_remision':
                return $request->validate([
                    'peso_default_kg' => 'sometimes|numeric|min:0.001',
                    'bultos_default' => 'sometimes|integer|min:1',
                    'verificacion_automatica' => 'sometimes|boolean',
                    'intentos_maximos_verificacion' => 'sometimes|integer|min:1|max:20',
                    'intervalo_verificacion_segundos' => 'sometimes|integer|min:10|max:300',
                    'timeout_consulta_segundos' => 'sometimes|integer|min:30|max:600',
                    'reintento_envio_fallido' => 'sometimes|integer|min:0|max:10',
                    'modalidad_transporte_default' => 'sometimes|in:01,02',
                    'motivo_traslado_default' => 'sometimes|in:01,02,03,04,05,06,07,08,09,13,14,18,19',
                ]);
                
            case 'servicios_sunat':
                return $request->validate([
                    'facturacion.beta.endpoint' => 'sometimes|url',
                    'facturacion.beta.wsdl' => 'sometimes|url',
                    'facturacion.beta.timeout' => 'sometimes|integer|min:5|max:300',
                    'facturacion.produccion.endpoint' => 'sometimes|url',
                    'facturacion.produccion.wsdl' => 'sometimes|url',
                    'facturacion.produccion.timeout' => 'sometimes|integer|min:5|max:300',
                    'guias_remision.beta.endpoint' => 'sometimes|url',
                    'guias_remision.beta.api_endpoint' => 'sometimes|url',
                    'guias_remision.beta.timeout' => 'sometimes|integer|min:5|max:300',
                    'guias_remision.produccion.endpoint' => 'sometimes|url',
                    'guias_remision.produccion.api_endpoint' => 'sometimes|url',
                    'guias_remision.produccion.timeout' => 'sometimes|integer|min:5|max:300',
                ]);
                
            case 'documentos':
                return $request->validate([
                    'generar_xml_automatico' => 'sometimes|boolean',
                    'generar_pdf_automatico' => 'sometimes|boolean',
                    'enviar_sunat_automatico' => 'sometimes|boolean',
                    'formato_pdf_default' => 'sometimes|in:a4,letter,legal',
                    'orientacion_pdf_default' => 'sometimes|in:portrait,landscape',
                    'incluir_qr_pdf' => 'sometimes|boolean',
                    'incluir_hash_pdf' => 'sometimes|boolean',
                    'logo_en_pdf' => 'sometimes|boolean',
                ]);
                
            default:
                return $request->all();
        }
    }
}
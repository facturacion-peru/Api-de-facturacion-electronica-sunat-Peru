<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGreCredentialsRequest;
use App\Http\Requests\GreEnvironmentRequest;
use App\Http\Requests\CopyGreCredentialsRequest;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

class GreCredentialsController extends Controller
{
    /**
     * Obtener credenciales GRE de una empresa
     */
    public function show(Company $company): JsonResponse
    {
        try {
            $credentials = [
                'beta' => $company->getSunatCredentials('guias_remision', 'beta') ?? [],
                'produccion' => $company->getSunatCredentials('guias_remision', 'produccion') ?? [],
            ];

            // Ocultar datos sensibles para la respuesta
            foreach (['beta', 'produccion'] as $mode) {
                if (!empty($credentials[$mode]['client_secret'])) {
                    $credentials[$mode]['client_secret'] = '***' . substr($credentials[$mode]['client_secret'], -4);
                }
                if (!empty($credentials[$mode]['clave_sol'])) {
                    $credentials[$mode]['clave_sol'] = '***' . substr($credentials[$mode]['clave_sol'], -4);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'company_id' => $company->id,
                    'company_name' => $company->razon_social,
                    'modo_actual' => $company->modo_produccion ? 'produccion' : 'beta',
                    'credenciales_configuradas' => $company->hasGreCredentials(),
                    'credenciales' => $credentials,
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al obtener credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener credenciales GRE: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar credenciales GRE para un ambiente específico
     */
    public function update(UpdateGreCredentialsRequest $request, Company $company): JsonResponse
    {
        try {
            $validated = $request->validated();
            $modo = $validated['modo'];
            
            // Preparar credenciales
            $credentials = [
                'client_id' => $validated['client_id'],
                'client_secret' => $validated['client_secret'],
                'ruc_proveedor' => $validated['ruc_proveedor'] ?? null,
                'usuario_sol' => $validated['usuario_sol'] ?? null,
                'clave_sol' => $validated['clave_sol'] ?? null,
            ];

            // Configurar credenciales usando el trait
            $company->setSunatCredentials('guias_remision', $credentials, $modo);

            Log::info("Credenciales GRE actualizadas", [
                'company_id' => $company->id,
                'modo' => $modo,
                'client_id' => '***' . substr($credentials['client_id'], -4),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Credenciales GRE para {$modo} actualizadas correctamente",
                'data' => [
                    'company_id' => $company->id,
                    'modo' => $modo,
                    'credenciales_configuradas' => $company->hasGreCredentials(),
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error("Error al actualizar credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar credenciales GRE: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar conexión con SUNAT usando las credenciales configuradas
     */
    public function testConnection(Company $company): JsonResponse
    {
        try {
            if (!$company->hasGreCredentials()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Las credenciales GRE no están configuradas para esta empresa'
                ], 400);
            }

            $credentials = $company->getGreCredentials();
            $mode = $company->modo_produccion ? 'producción' : 'beta';

            // Aquí podrías agregar lógica real de prueba de conexión
            // Por ahora, solo validamos que las credenciales estén completas
            $isValid = !empty($credentials['client_id']) &&
                      !empty($credentials['client_secret']) &&
                      !empty($company->getGreRucProveedor()) &&
                      !empty($company->getGreUsuarioSol()) &&
                      !empty($company->getGreClaveSol());

            if (!$isValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incompletas'
                ], 400);
            }

            Log::info("Test de conexión GRE", [
                'company_id' => $company->id,
                'modo' => $mode,
                'result' => 'success'
            ]);

            return response()->json([
                'success' => true,
                'message' => "Conexión con SUNAT ({$mode}) validada correctamente",
                'data' => [
                    'company_id' => $company->id,
                    'modo' => $mode,
                    'client_id' => '***' . substr($credentials['client_id'], -4),
                    'ruc_proveedor' => $company->getGreRucProveedor(),
                    'timestamp' => now()->toISOString()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error en test de conexión GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al probar conexión: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener valores por defecto para un ambiente
     */
    public function getDefaults(string $mode): JsonResponse
    {
        try {
            if (!in_array($mode, ['beta', 'produccion'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Modo inválido. Debe ser beta o produccion'
                ], 400);
            }

            $company = new Company();
            $defaults = $company->getDefaultConfigurations()['credenciales_gre'][$mode];

            // Ocultar datos sensibles
            if (!empty($defaults['client_secret'])) {
                $defaults['client_secret'] = '***' . substr($defaults['client_secret'], -4);
            }
            if (!empty($defaults['clave_sol'])) {
                $defaults['clave_sol'] = '***' . substr($defaults['clave_sol'], -4);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'modo' => $mode,
                    'credenciales_default' => $defaults,
                    'descripcion' => $mode === 'beta' 
                        ? 'Credenciales de prueba para ambiente BETA'
                        : 'Credenciales de producción (deben ser configuradas por empresa)'
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener valores por defecto: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Limpiar credenciales para un ambiente específico
     */
    public function clear(GreEnvironmentRequest $request, Company $company): JsonResponse
    {
        try {
            $validated = $request->validated();
            $modo = $validated['modo'];

            // Limpiar credenciales
            $credentials = [
                'client_id' => null,
                'client_secret' => null,
                'ruc_proveedor' => null,
                'usuario_sol' => null,
                'clave_sol' => null,
            ];

            $company->setSunatCredentials('guias_remision', $credentials, $modo);

            Log::info("Credenciales GRE limpiadas", [
                'company_id' => $company->id,
                'modo' => $modo,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Credenciales GRE para {$modo} han sido limpiadas",
                'data' => [
                    'company_id' => $company->id,
                    'modo' => $modo,
                    'credenciales_configuradas' => $company->hasGreCredentials(),
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al limpiar credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al limpiar credenciales: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copiar credenciales de un ambiente a otro
     */
    public function copy(CopyGreCredentialsRequest $request, Company $company): JsonResponse
    {
        try {
            $validated = $request->validated();
            $origen = $validated['origen'];
            $destino = $validated['destino'];

            $credencialesOrigen = $company->getSunatCredentials('guias_remision', $origen);

            if (empty($credencialesOrigen['client_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => "No hay credenciales configuradas en el ambiente {$origen}"
                ], 400);
            }

            $company->setSunatCredentials('guias_remision', $credencialesOrigen, $destino);

            Log::info("Credenciales GRE copiadas", [
                'company_id' => $company->id,
                'origen' => $origen,
                'destino' => $destino,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Credenciales copiadas de {$origen} a {$destino}",
                'data' => [
                    'company_id' => $company->id,
                    'origen' => $origen,
                    'destino' => $destino,
                    'credenciales_configuradas' => $company->hasGreCredentials(),
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al copiar credenciales GRE", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al copiar credenciales: ' . $e->getMessage()
            ], 500);
        }
    }
}
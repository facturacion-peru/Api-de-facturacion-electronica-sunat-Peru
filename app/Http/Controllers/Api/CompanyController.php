<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class CompanyController extends Controller
{
    /**
     * Listar todas las empresas
     */
    public function index(): JsonResponse
    {
        try {
            $companies = Company::active()
                ->with(['branches'])
                ->select([
                    'id', 'ruc', 'razon_social', 'nombre_comercial', 
                    'direccion', 'distrito', 'provincia', 'departamento',
                    'email', 'telefono', 'modo_produccion', 'activo',
                    'created_at', 'updated_at'
                ])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $companies,
                'meta' => [
                    'total' => $companies->count(),
                    'active_count' => $companies->where('activo', true)->count(),
                    'production_count' => $companies->where('modo_produccion', true)->count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al listar empresas", [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empresas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nueva empresa
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ruc' => 'required|string|size:11|unique:companies,ruc',
                'razon_social' => 'required|string|max:255',
                'nombre_comercial' => 'nullable|string|max:255',
                'direccion' => 'required|string|max:255',
                'ubigeo' => 'required|string|size:6',
                'distrito' => 'required|string|max:100',
                'provincia' => 'required|string|max:100',
                'departamento' => 'required|string|max:100',
                'telefono' => 'nullable|string|max:20',
                'email' => 'required|email|max:255',
                'web' => 'nullable|url|max:255',
                'usuario_sol' => 'required|string|max:50',
                'clave_sol' => 'required|string|max:100',
                'certificado_pem' => 'nullable|file|mimes:pem,crt,cer,txt|max:2048',
                'certificado_password' => 'nullable|string|max:100',
                'endpoint_beta' => 'nullable|url|max:255',
                'endpoint_produccion' => 'nullable|url|max:255',
                'modo_produccion' => 'boolean',
                'logo_path' => 'nullable|string|max:255',
                'activo' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();
            
            // Procesar certificado PEM si se subió un archivo
            if ($request->hasFile('certificado_pem')) {
                $certificateFile = $request->file('certificado_pem');
                $fileName = 'certificado.pem';
                $path = $certificateFile->storeAs('certificado', $fileName, 'public');
                $validatedData['certificado_pem'] = $path;
            }

            $company = Company::create($validatedData);

            Log::info("Empresa creada exitosamente", [
                'company_id' => $company->id,
                'ruc' => $company->ruc,
                'razon_social' => $company->razon_social
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Empresa creada exitosamente',
                'data' => $company->load('configurations')
            ], 201);

        } catch (Exception $e) {
            Log::error("Error al crear empresa", [
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear empresa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener empresa específica
     */
    public function show(Company $company): JsonResponse
    {
        try {
            $company->load([
                'branches',
                'configurations' => function($query) {
                    $query->active()->orderBy('config_type')->orderBy('environment');
                }
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'company' => $company,
                    'stats' => [
                        'branches_count' => $company->branches()->count(),
                        'configurations_count' => $company->configurations()->active()->count(),
                        'has_gre_credentials' => $company->hasGreCredentials(),
                        'environment_mode' => $company->modo_produccion ? 'produccion' : 'beta'
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al obtener empresa", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empresa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar empresa
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ruc' => 'required|string|size:11|unique:companies,ruc,' . $company->id,
                'razon_social' => 'required|string|max:255',
                'nombre_comercial' => 'nullable|string|max:255',
                'direccion' => 'required|string|max:255',
                'ubigeo' => 'required|string|size:6',
                'distrito' => 'required|string|max:100',
                'provincia' => 'required|string|max:100',
                'departamento' => 'required|string|max:100',
                'telefono' => 'nullable|string|max:20',
                'email' => 'required|email|max:255',
                'web' => 'nullable|url|max:255',
                'usuario_sol' => 'required|string|max:50',
                'clave_sol' => 'required|string|max:100',
                'certificado_pem' => 'nullable|file|mimes:pem,crt,cer,txt|max:2048',
                'certificado_password' => 'nullable|string|max:100',
                'endpoint_beta' => 'nullable|url|max:255',
                'endpoint_produccion' => 'nullable|url|max:255',
                'modo_produccion' => 'boolean',
                'logo_path' => 'nullable|string|max:255',
                'activo' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();
            
            // Procesar certificado PEM si se subió un archivo
            if ($request->hasFile('certificado_pem')) {
                $certificateFile = $request->file('certificado_pem');
                $fileName = 'certificado.pem';
                $path = $certificateFile->storeAs('certificado', $fileName, 'public');
                $validatedData['certificado_pem'] = $path;
            }

            $company->update($validatedData);

            Log::info("Empresa actualizada exitosamente", [
                'company_id' => $company->id,
                'ruc' => $company->ruc,
                'changes' => $company->getChanges()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Empresa actualizada exitosamente',
                'data' => $company->fresh()->load('configurations')
            ]);

        } catch (Exception $e) {
            Log::error("Error al actualizar empresa", [
                'company_id' => $company->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar empresa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar empresa (soft delete)
     */
    public function destroy(Company $company): JsonResponse
    {
        try {
            // Verificar si la empresa tiene documentos asociados
            $hasDocuments = $company->invoices()->count() > 0 ||
                          $company->boletas()->count() > 0 ||
                          $company->dispatchGuides()->count() > 0;

            if ($hasDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar la empresa porque tiene documentos asociados. Considere desactivarla en su lugar.'
                ], 400);
            }

            // Marcar como inactiva en lugar de eliminar
            $company->update(['activo' => false]);

            Log::warning("Empresa desactivada", [
                'company_id' => $company->id,
                'ruc' => $company->ruc
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Empresa desactivada exitosamente'
            ]);

        } catch (Exception $e) {
            Log::error("Error al desactivar empresa", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar empresa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activar empresa
     */
    public function activate(Company $company): JsonResponse
    {
        try {
            $company->update(['activo' => true]);

            Log::info("Empresa activada", [
                'company_id' => $company->id,
                'ruc' => $company->ruc
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Empresa activada exitosamente',
                'data' => $company
            ]);

        } catch (Exception $e) {
            Log::error("Error al activar empresa", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al activar empresa: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar modo de producción
     */
    public function toggleProductionMode(Request $request, Company $company): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'modo_produccion' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $oldMode = $company->modo_produccion;
            $newMode = $request->modo_produccion;

            $company->update(['modo_produccion' => $newMode]);

            Log::info("Modo de producción cambiado", [
                'company_id' => $company->id,
                'ruc' => $company->ruc,
                'old_mode' => $oldMode ? 'produccion' : 'beta',
                'new_mode' => $newMode ? 'produccion' : 'beta'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Modo de producción actualizado exitosamente',
                'data' => [
                    'company_id' => $company->id,
                    'modo_anterior' => $oldMode ? 'produccion' : 'beta',
                    'modo_actual' => $newMode ? 'produccion' : 'beta'
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error al cambiar modo de producción", [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar modo de producción: ' . $e->getMessage()
            ], 500);
        }
    }
}
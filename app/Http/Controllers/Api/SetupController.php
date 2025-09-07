<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SetupController extends Controller
{
    /**
     * Setup completo del sistema
     */
    public function setup(Request $request)
    {
        $request->validate([
            'environment' => 'required|in:beta,production',
            'company' => 'required|array',
            'company.ruc' => 'required|string|size:11',
            'company.razon_social' => 'required|string|max:255',
            'company.nombre_comercial' => 'nullable|string|max:255',
            'company.direccion' => 'required|string',
            'company.ubigeo' => 'required|string|size:6',
            'company.distrito' => 'required|string',
            'company.provincia' => 'required|string',
            'company.departamento' => 'required|string',
            'company.telefono' => 'nullable|string',
            'company.email' => 'nullable|email',
            'company.usuario_sol' => 'required|string',
            'company.clave_sol' => 'required|string',
            'certificate_content' => 'nullable|string',
            'certificate_password' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // 1. Crear/Actualizar empresa
            $companyData = $request->company;
            $companyData['environment'] = $request->environment;
            
            if ($request->filled('certificate_content') && $request->filled('certificate_password')) {
                $certificatePath = $this->saveCertificate($request->certificate_content, $request->environment);
                $companyData['certificado_pem'] = $certificatePath;
                $companyData['certificado_password'] = $request->certificate_password;
            }

            $company = Company::updateOrCreate(
                ['ruc' => $companyData['ruc']],
                $companyData
            );

            // 2. Crear sucursal principal si no existe
            $branch = Branch::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'codigo' => '0000'
                ],
                [
                    'nombre' => 'Sucursal Principal',
                    'direccion' => $company->direccion,
                    'ubigeo' => $company->ubigeo,
                    'distrito' => $company->distrito,
                    'provincia' => $company->provincia,
                    'departamento' => $company->departamento,
                    'activo' => true,
                ]
            );

            // 3. Configurar empresa para SUNAT
            $this->setupCompanyForSunat($company, $request->environment);

            // 4. Asignar empresa al usuario actual si no tiene una
            $user = $request->user();
            if (!$user->company_id && $user->role && $user->role->name !== 'super_admin') {
                $user->update(['company_id' => $company->id]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Setup completado exitosamente',
                'company' => [
                    'id' => $company->id,
                    'ruc' => $company->ruc,
                    'razon_social' => $company->razon_social,
                    'environment' => $request->environment,
                    'has_certificate' => !empty($company->certificado_pem),
                    'branch_count' => $company->branches()->count()
                ],
                'branch' => [
                    'id' => $branch->id,
                    'codigo' => $branch->codigo,
                    'nombre' => $branch->nombre
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error en setup: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Migrar base de datos
     */
    public function migrate(Request $request)
    {
        try {
            \Artisan::call('migrate', ['--force' => true]);
            $output = \Artisan::output();

            return response()->json([
                'message' => 'Migraciones ejecutadas exitosamente',
                'output' => $output
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al ejecutar migraciones: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Seed de producción
     */
    public function seed(Request $request)
    {
        try {
            \Artisan::call('db:seed', [
                '--class' => 'ProductionSeeder',
                '--force' => true
            ]);
            $output = \Artisan::output();

            return response()->json([
                'message' => 'Seeder ejecutado exitosamente',
                'output' => $output
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al ejecutar seeder: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Estado del sistema
     */
    public function status()
    {
        try {
            $status = [
                'database_connected' => $this->checkDatabaseConnection(),
                'migrations_pending' => $this->checkMigrationsPending(),
                'companies_count' => Company::count(),
                'users_count' => User::count(),
                'storage_writable' => is_writable(storage_path()),
                'certificates_directory' => $this->checkCertificatesDirectory(),
                'environment' => config('app.env'),
                'debug' => config('app.debug'),
                'app_key_set' => !empty(config('app.key')),
            ];

            $status['ready_for_use'] = $status['database_connected'] && 
                                      !$status['migrations_pending'] && 
                                      $status['companies_count'] > 0 &&
                                      $status['app_key_set'];

            return response()->json([
                'system_status' => $status
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener estado: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Configuración del entorno SUNAT
     */
    public function configureSunat(Request $request)
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'environment' => 'required|in:beta,production',
            'certificate_file' => 'nullable|file',
            'certificate_password' => 'nullable|string',
            'force_update' => 'boolean'
        ]);

        try {
            $company = Company::findOrFail($request->company_id);

            // Verificar permisos
            if (!$request->user()->hasRole('super_admin') && 
                $request->user()->company_id !== $company->id) {
                return response()->json([
                    'message' => 'No tienes permisos para configurar esta empresa',
                    'status' => 'error'
                ], 403);
            }

            // Configurar certificado si se proporciona
            if ($request->hasFile('certificate_file')) {
                $this->storeCertificateInExpectedLocation($request->file('certificate_file'));
                
                $company->update([
                    'certificado_pem' => 'public/certificado/certificado.pem',
                    'certificado_password' => $request->certificate_password
                ]);
            }

            // Configurar para SUNAT
            $this->setupCompanyForSunat($company, $request->environment);

            return response()->json([
                'message' => 'Configuración SUNAT actualizada exitosamente',
                'company' => [
                    'id' => $company->id,
                    'ruc' => $company->ruc,
                    'environment' => $request->environment,
                    'has_certificate' => !empty($company->certificado_pem)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al configurar SUNAT: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    /**
     * Configurar empresa para SUNAT
     */
    private function setupCompanyForSunat(Company $company, string $environment)
    {
        $config = [
            'environment' => $environment,
            'services' => [
                'facturacion' => [
                    'beta' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
                    'produccion' => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService'
                ],
                'guias' => [
                    'beta' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService',
                    'produccion' => 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService'
                ],
                'consultas' => [
                    'beta' => 'https://e-beta.sunat.gob.pe/ol-it-wsconscpegem-beta/billConsultService',
                    'produccion' => 'https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService'
                ]
            ],
            'certificados' => [
                'ruta_certificado' => $company->certificado_pem,
                'password_certificado' => $company->certificado_password
            ],
            'configuraciones_avanzadas' => [
                'timeout_conexion' => 30,
                'reintentos_automaticos' => 3,
                'validar_ssl' => $environment === 'produccion',
                'formato_fecha' => 'Y-m-d',
                'zona_horaria' => 'America/Lima'
            ]
        ];

        // Guardar configuraciones usando el array configuraciones
        $company->update(['configuraciones' => $config]);
    }

    /**
     * Guardar certificado
     */
    private function saveCertificate(string $content, string $environment): string
    {
        $path = 'certificado';
        Storage::disk('public')->makeDirectory($path);
        
        $fullPath = "{$path}/certificado.pem";
        
        Storage::disk('public')->put($fullPath, base64_decode($content));
        
        return 'public/certificado/certificado.pem';
    }

    /**
     * Almacenar archivo de certificado
     */
    private function storeCertificate($file, string $environment): string
    {
        $path = "certificates/{$environment}";
        return $file->store($path, 'local');
    }

    /**
     * Almacenar certificado en la ubicación esperada por el sistema
     */
    private function storeCertificateInExpectedLocation($file): void
    {
        $path = 'certificado';
        Storage::disk('public')->makeDirectory($path);
        
        // Guardar con el nombre específico que espera el sistema
        Storage::disk('public')->putFileAs($path, $file, 'certificado.pem');
    }

    /**
     * Verificar conexión a base de datos
     */
    private function checkDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verificar migraciones pendientes
     */
    private function checkMigrationsPending(): bool
    {
        try {
            \Artisan::call('migrate:status');
            $output = \Artisan::output();
            return str_contains($output, 'Pending');
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Verificar directorio de certificados
     */
    private function checkCertificatesDirectory(): array
    {
        $beta = Storage::disk('local')->exists('certificates/beta');
        $production = Storage::disk('local')->exists('certificates/production');
        
        if (!$beta) Storage::disk('local')->makeDirectory('certificates/beta');
        if (!$production) Storage::disk('local')->makeDirectory('certificates/production');
        
        return [
            'beta' => $beta,
            'production' => $production
        ];
    }
}
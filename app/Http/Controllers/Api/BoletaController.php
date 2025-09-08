<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Models\Boleta;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Http\Requests\IndexBoletaRequest;
use App\Http\Requests\StoreBoletaRequest;
use App\Http\Requests\CreateDailySummaryRequest;
use App\Http\Requests\GetBoletasPendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BoletaController extends Controller
{
    use HandlesPdfGeneration;
    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    public function index(IndexBoletaRequest $request): JsonResponse
    {
        $query = Boleta::with(['company', 'branch', 'client']);

        // Filtros
        if ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }

        if ($request->has('fecha_desde')) {
            $query->whereDate('fecha_emision', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->whereDate('fecha_emision', '<=', $request->fecha_hasta);
        }

        $perPage = $request->get('per_page', 15);
        $boletas = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $boletas->items(),
            'pagination' => [
                'current_page' => $boletas->currentPage(),
                'last_page' => $boletas->lastPage(),
                'per_page' => $boletas->perPage(),
                'total' => $boletas->total(),
            ]
        ]);
    }

    public function store(StoreBoletaRequest $request): JsonResponse
    {
        
        try {
            $validated = $request->validated();
            /* return response()->json(['datos' => $validated], 200); */
            $boleta = $this->documentService->createBoleta($validated);

            return response()->json([
                'success' => true,
                'data' => $boleta->load(['company', 'branch', 'client']),
                'message' => 'Boleta creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la boleta: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $boleta
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Boleta no encontrada'
            ], 404);
        }
    }

    public function sendToSunat(string $id): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            
            if ($boleta->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'La boleta ya fue aceptada por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendToSunat($boleta, 'boleta');
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'client']),
                    'message' => 'Boleta enviada exitosamente a SUNAT'
                ]);
            } else {
                // Manejar diferentes tipos de error
                $errorCode = 'UNKNOWN';
                $errorMessage = 'Error desconocido';
                
                if (is_object($result['error'])) {
                    if (method_exists($result['error'], 'getCode')) {
                        $errorCode = $result['error']->getCode();
                    } elseif (property_exists($result['error'], 'code')) {
                        $errorCode = $result['error']->code;
                    }
                    
                    if (method_exists($result['error'], 'getMessage')) {
                        $errorMessage = $result['error']->getMessage();
                    } elseif (property_exists($result['error'], 'message')) {
                        $errorMessage = $result['error']->message;
                    }
                }
                
                return response()->json([
                    'success' => false,
                    'data' => $result['document'],
                    'message' => 'Error al enviar a SUNAT: ' . $errorMessage,
                    'error_code' => $errorCode
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadXml(string $id): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $boleta = Boleta::findOrFail($id);
            
            if (empty($boleta->xml_path) || !file_exists(storage_path('app/' . $boleta->xml_path))) {
                return response()->json([
                    'success' => false,
                    'message' => 'XML no encontrado'
                ], 404);
            }

            return response()->download(
                storage_path('app/' . $boleta->xml_path),
                $boleta->numero_completo . '.xml',
                ['Content-Type' => 'application/xml']
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar XML: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadCdr(string $id): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $boleta = Boleta::findOrFail($id);
            
            if (empty($boleta->cdr_path) || !file_exists(storage_path('app/' . $boleta->cdr_path))) {
                return response()->json([
                    'success' => false,
                    'message' => 'CDR no encontrado'
                ], 404);
            }

            return response()->download(
                storage_path('app/' . $boleta->cdr_path),
                'R-' . $boleta->numero_completo . '.zip',
                ['Content-Type' => 'application/zip']
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar CDR: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadPdf($id, Request $request)
    {
        $boleta = Boleta::findOrFail($id);
        return $this->downloadDocumentPdf($boleta, $request);
    }

    public function createDailySummaryFromDate(CreateDailySummaryRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear resumen diario automáticamente desde las boletas pendientes
            $summary = $this->documentService->createSummaryFromBoletas($validated);

            return response()->json([
                'success' => true,
                'data' => $summary->load(['company', 'branch', 'boletas']),
                'message' => 'Resumen diario creado correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear resumen diario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendSummaryToSunat($summaryId): JsonResponse
    {
        try {
            $summary = \App\Models\DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($summaryId);

            if ($summary->estado_sunat === 'ACEPTADO') {
                return response()->json([
                    'success' => false,
                    'message' => 'El resumen ya fue aceptado por SUNAT'
                ], 400);
            }

            $result = $this->documentService->sendDailySummaryToSunat($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'boletas']),
                    'ticket' => $result['ticket'],
                    'message' => 'Resumen enviado correctamente a SUNAT'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'data' => $result['document']->load(['company', 'branch', 'boletas']),
                    'message' => 'Error al enviar resumen a SUNAT',
                    'error' => $result['error']
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkSummaryStatus($summaryId): JsonResponse
    {
        try {
            $summary = \App\Models\DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($summaryId);

            $result = $this->documentService->checkSummaryStatus($summary);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['document']->load(['company', 'branch', 'boletas']),
                    'message' => 'Estado del resumen consultado correctamente'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al consultar estado: ' . ($result['error'] ?? 'Error desconocido')
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getBoletsasPendingForSummary(GetBoletasPendingRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $boletas = Boleta::with(['company', 'branch', 'client'])
                            ->where('company_id', $validated['company_id'])
                            ->where('branch_id', $validated['branch_id'])
                            ->whereDate('fecha_emision', $validated['fecha_emision'])
                            ->where('estado_sunat', 'PENDIENTE')
                            ->whereNull('daily_summary_id')
                            ->get();

            return response()->json([
                'success' => true,
                'data' => $boletas,
                'total' => $boletas->count(),
                'message' => 'Boletas pendientes obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener boletas pendientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generatePdf($id, Request $request)
    {
        $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
        return $this->generateDocumentPdf($boleta, 'boleta', $request);
    }
}
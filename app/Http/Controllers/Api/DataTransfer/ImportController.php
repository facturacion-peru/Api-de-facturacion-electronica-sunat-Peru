<?php

namespace App\Http\Controllers\Api\DataTransfer;

use App\DataTransfer\Imports\ImportApplier;
use App\DataTransfer\Imports\ImportPreviewer;
use App\DataTransfer\Imports\Templates;
use App\Establishments\EstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataTransfer\PreviewImportRequest;
use App\Http\Resources\ImportPreviewResource;
use App\Models\Establishment;
use App\Models\ImportPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Importaciones de la spec 014: plantilla, vista previa y confirmación. */
class ImportController extends Controller
{
    public function template(string $kind, Templates $templates): StreamedResponse
    {
        $file = $templates->build($kind);

        return response()->streamDownload(function () use ($file) {
            readfile($file->path);
            $file->delete();
        }, $file->filename, ['Content-Type' => $file->mimeType]);
    }

    public function preview(PreviewImportRequest $request, string $kind, ImportPreviewer $previewer, EstablishmentAccess $access): ImportPreviewResource
    {
        $establishment = $request->filled('establishment_id')
            ? $access->resolve($request->user(), $request->integer('establishment_id'))
            : Establishment::where('is_main', true)->firstOrFail();

        return new ImportPreviewResource($previewer->preview($kind, $request->mode(), $request->file('file'), $request->user(), $establishment));
    }

    /** Solo la confirma quien la creó; para los demás no existe (404). */
    public function confirm(Request $request, ImportPreview $preview, ImportApplier $applier): JsonResponse
    {
        abort_unless($preview->user_id === $request->user()->id, 404, 'Recurso no encontrado.');

        return response()->json(['success' => true, 'data' => $applier->confirm($preview, $request->user())]);
    }
}

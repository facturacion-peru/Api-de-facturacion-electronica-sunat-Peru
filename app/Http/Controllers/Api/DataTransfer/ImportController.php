<?php

namespace App\Http\Controllers\Api\DataTransfer;

use App\DataTransfer\Imports\ImportPreviewer;
use App\DataTransfer\Imports\Templates;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataTransfer\PreviewImportRequest;
use App\Http\Resources\ImportPreviewResource;
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

    public function preview(PreviewImportRequest $request, string $kind, ImportPreviewer $previewer): ImportPreviewResource
    {
        return new ImportPreviewResource($previewer->preview($kind, $request->mode(), $request->file('file'), $request->user()));
    }
}

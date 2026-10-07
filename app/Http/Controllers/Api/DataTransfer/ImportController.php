<?php

namespace App\Http\Controllers\Api\DataTransfer;

use App\DataTransfer\Imports\Templates;
use App\Http\Controllers\Controller;
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
}

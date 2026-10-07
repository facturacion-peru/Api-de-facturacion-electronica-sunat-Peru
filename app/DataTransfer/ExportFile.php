<?php

namespace App\DataTransfer;

/** Archivo generado en una carpeta temporal propia, listo para descargar y borrar. */
final readonly class ExportFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType,
    ) {}

    /** Borra la carpeta temporal con todo lo que se generó en ella. */
    public function delete(): void
    {
        $directory = dirname($this->path);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}

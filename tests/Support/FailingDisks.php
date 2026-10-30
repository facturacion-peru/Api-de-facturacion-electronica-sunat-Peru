<?php

namespace Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;

/**
 * Discos de imágenes que fallan (spec 016, RF-010), para probar que la API
 * sigue en pie cuando S3 no responde.
 */
final class FailingDisks
{
    /** S3 real apuntando a un puerto cerrado: falla toda operación de red, pero `url()` funciona. */
    public static function unreachable(): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 's3',
            'key' => 'clave-de-prueba',
            'secret' => 'secreto-de-prueba',
            'region' => 'us-east-1',
            'bucket' => 'bucket-de-prueba',
            'endpoint' => 'http://127.0.0.1:9',
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'http' => ['connect_timeout' => 1, 'timeout' => 2],
            'throw' => true,
        ]);
    }

    /** Escribe y lee en `$root` como el disco simulado, pero no puede borrar. */
    public static function withoutDeletes(string $root): FilesystemAdapter
    {
        $adapter = new class($root) extends LocalFilesystemAdapter
        {
            public function delete(string $path): void
            {
                throw UnableToDeleteFile::atLocation($path, 'Borrado simulado que falla.');
            }
        };

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => true]);
    }
}

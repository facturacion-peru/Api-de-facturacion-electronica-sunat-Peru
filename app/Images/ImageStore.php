<?php

namespace App\Images;

use App\Models\ImageDeletion;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Objetos del disco de imágenes (spec 016): S3 en producción o `public` en
 * local, según `filesystems.images_disk`. Las claves son UUID sin datos
 * (RF-005). Un borrado que falla no interrumpe nada: queda en
 * `image_deletions` para `images:cleanup`.
 */
class ImageStore
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.images_disk'));
    }

    /** @return list<string> */
    public static function productPaths(string $key): array
    {
        return ["{$key}.webp", "{$key}-thumb.webp"];
    }

    public static function logoPath(string $key): string
    {
        return "{$key}.png";
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    /**
     * Sube los objetos de una vez: si uno falla, los ya subidos van a la cola
     * de borrado y se lanza ImageStorageUnavailable.
     *
     * @param  array<string, string>  $objects  Ruta => bytes.
     */
    public function put(array $objects, string $contentType, ?string $failureMessage = null): void
    {
        $written = [];

        try {
            foreach ($objects as $path => $bytes) {
                // `throw => true` en S3; el disco local devuelve false.
                if (! $this->disk()->put($path, $bytes, ['ContentType' => $contentType])) {
                    throw new \RuntimeException("No se escribió {$path}.");
                }
                $written[] = $path;
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo subir una imagen al disco de imágenes.', ['error' => $e->getMessage()]);
            $this->delete(...$written);

            throw $failureMessage === null ? new ImageStorageUnavailable(previous: $e) : new ImageStorageUnavailable($failureMessage, $e);
        }
    }

    /** Borra sin lanzar: lo que no se pueda borrar queda para `images:cleanup`. */
    public function delete(string ...$paths): void
    {
        $pending = [];

        foreach ($paths as $path) {
            try {
                if (! $this->disk()->delete($path)) {
                    throw new \RuntimeException("No se borró {$path}.");
                }
            } catch (Throwable $e) {
                $pending[] = $path;
                Log::warning('No se pudo borrar una imagen; queda pendiente.', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        if ($pending !== []) {
            ImageDeletion::enqueue(...$pending);
        }
    }
}

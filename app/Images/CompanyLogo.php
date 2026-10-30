<?php

namespace App\Images;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lectura del logo de la empresa (spec 016, HU-4). Una clave sin «/» está en
 * el disco de imágenes; una ruta antigua (`companies/{id}/…`) sigue en
 * `public` hasta que `images:move-logos` la mueva.
 */
class CompanyLogo
{
    public const CACHE_SECONDS = 86400;

    public function __construct(private ImageStore $store) {}

    public static function isLegacy(string $path): bool
    {
        return str_contains($path, '/');
    }

    public function url(?string $path): ?string
    {
        return $path === null ? null : $this->diskFor($path)->url($path);
    }

    /**
     * El logo como data URI para los PDF, o null si no hay o no se pudo leer:
     * el PDF sale sin logo antes que fallar (RF-014). Se guarda un día en
     * caché por clave; una clave nueva por logo hace que nunca quede vieja.
     */
    public function dataUri(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        try {
            return Cache::remember('company-logo:'.sha1($path), self::CACHE_SECONDS, function () use ($path) {
                $disk = $this->diskFor($path);
                $bytes = $disk->get($path);

                if ($bytes === null) {
                    // `null` no se guarda en caché: se vuelve a intentar en el siguiente PDF.
                    return null;
                }

                $mime = Str::endsWith($path, '.png') ? 'image/png' : ($disk->mimeType($path) ?: 'image/png');

                return "data:{$mime};base64,".base64_encode($bytes);
            });
        } catch (Throwable $e) {
            Log::warning('No se pudo leer el logo para el PDF; sale sin logo.', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function diskFor(string $path): Filesystem
    {
        return self::isLegacy($path) ? Storage::disk('public') : $this->store->disk();
    }
}

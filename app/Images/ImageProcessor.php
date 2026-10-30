<?php

namespace App\Images;

/**
 * Foto del producto (spec 016, A-81): JPEG, PNG o WebP de hasta 4000 px a dos
 * WebP, la grande (cabe en 800 px) y la miniatura (200 px), sin agrandar,
 * derechas según el EXIF y sin metadatos. La app ya la reduce y la endereza
 * (A-85); esto es la defensa para cualquier otro cliente.
 */
final class ImageProcessor
{
    public const LARGE = 800;

    public const THUMB = 200;

    private const QUALITY = 80;

    public function process(string $bytes): ProcessedImage
    {
        [$original, $type] = Gd::decode($bytes, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP]);

        // Se reduce antes de girar: girar una foto de 4000 px duplicaría la memoria.
        // El recuadro es cuadrado, así que reducir primero da las mismas medidas.
        $large = Gd::fit($original, self::LARGE, self::LARGE);
        unset($original);

        if ($type === IMAGETYPE_JPEG) {
            $large = Gd::orient($large, $bytes);
        }

        return new ProcessedImage(
            large: Gd::webp($large, self::QUALITY),
            thumb: Gd::webp(Gd::fit($large, self::THUMB, self::THUMB), self::QUALITY),
        );
    }
}

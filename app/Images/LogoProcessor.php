<?php

namespace App\Images;

/**
 * Logo de la empresa (spec 016, HU-4): PNG o JPEG a PNG con alfa, para que un
 * logo transparente siga siéndolo en el PDF. Hasta 800 px de ancho, derecho
 * según el EXIF y sin metadatos.
 */
final class LogoProcessor
{
    public const MAX_WIDTH = 800;

    public function process(string $bytes): string
    {
        [$original, $type] = Gd::decode($bytes, [IMAGETYPE_PNG, IMAGETYPE_JPEG]);

        $logo = $type === IMAGETYPE_JPEG ? Gd::orient($original, $bytes) : $original;

        return Gd::png(Gd::fit($logo, self::MAX_WIDTH, Gd::MAX_SIDE));
    }
}

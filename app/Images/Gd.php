<?php

namespace App\Images;

use GdImage;

/**
 * Operaciones de GD que comparten la foto del producto y el logo (spec 016).
 * Toda salida se vuelve a codificar desde los píxeles: no pasa ningún metadato.
 */
final class Gd
{
    /** Lado máximo aceptado (A-80): protege la memoria de imágenes enormes o «bombas». */
    public const MAX_SIDE = 4000;

    /**
     * Decodifica solo los tipos permitidos y dentro de MAX_SIDE. Las dimensiones
     * se leen de la cabecera antes de decodificar.
     *
     * @param  list<int>  $types  Constantes IMAGETYPE_*.
     * @return array{0: GdImage, 1: int} La imagen y su tipo.
     */
    public static function decode(string $bytes, array $types): array
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2], $types, true)) {
            throw new InvalidImage('El archivo no es una imagen válida.');
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new InvalidImage('La imagen supera las dimensiones permitidas.');
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new InvalidImage('El archivo no es una imagen válida.');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return [$image, $info[2]];
    }

    /** Copia que cabe en el recuadro, con la proporción y el alfa; nunca agranda. */
    public static function fit(GdImage $image, int $maxWidth, int $maxHeight): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = self::blank($targetWidth, $targetHeight);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    /**
     * Aplica la orientación EXIF (1 a 8) de una JPEG: rotación y espejo. Los
     * celulares guardan la foto «acostada» y la marcan con este valor.
     */
    public static function orient(GdImage $image, string $jpegBytes): GdImage
    {
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpegBytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // imagerotate gira en sentido antihorario: -90 es a la derecha.
        [$angle, $mirror] = match ($orientation) {
            2 => [0, true],
            3 => [180, false],
            4 => [180, true],
            5 => [-90, true],
            6 => [-90, false],
            7 => [90, true],
            8 => [90, false],
            default => [0, false],
        };

        if ($angle !== 0) {
            $image = imagerotate($image, $angle, 0);
        }

        if ($mirror) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    public static function webp(GdImage $image, int $quality): string
    {
        return self::capture(fn () => imagewebp($image, null, $quality));
    }

    public static function png(GdImage $image): string
    {
        return self::capture(fn () => imagepng($image, null, 9));
    }

    private static function blank(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    /** @param  callable(): bool  $write */
    private static function capture(callable $write): string
    {
        ob_start();

        try {
            $write();
        } finally {
            $bytes = (string) ob_get_clean();
        }

        return $bytes;
    }
}

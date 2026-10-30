<?php

namespace Tests\Support;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Imágenes reales para pruebas (spec 016), hechas con GD. Las JPEG pueden
 * llevar un bloque EXIF armado a mano con orientación, cámara y GPS, como las
 * fotos de un celular: así se comprueba que la salida queda derecha y limpia.
 *
 * Todas tienen la esquina superior izquierda roja y el resto blanco (o
 * transparente en PNG), para saber hacia dónde quedó girada la salida.
 */
final class TestImages
{
    /** Marca de cámara del EXIF: no debe aparecer en ninguna salida. */
    public const CAMERA = 'CamaraDePrueba';

    /**
     * @param  int|null  $orientation  Orientación EXIF (1 a 8); sin ella, no hay bloque EXIF.
     * @param  bool  $gps  Agrega coordenadas GPS al EXIF.
     */
    public static function jpeg(int $width = 400, int $height = 200, ?int $orientation = null, bool $gps = false): string
    {
        $bytes = self::encode(self::canvas($width, $height), fn (GdImage $image) => imagejpeg($image, null, 90));

        if ($orientation === null && ! $gps) {
            return $bytes;
        }

        // El bloque APP1 va justo después del inicio de la imagen (FFD8).
        return substr($bytes, 0, 2).self::exifSegment($orientation ?? 1, $gps).substr($bytes, 2);
    }

    /** PNG con fondo transparente y la esquina roja opaca. */
    public static function png(int $width = 400, int $height = 200, bool $alpha = true): string
    {
        return self::encode(self::canvas($width, $height, $alpha), fn (GdImage $image) => imagepng($image));
    }

    public static function webp(int $width = 400, int $height = 200): string
    {
        return self::encode(self::canvas($width, $height), fn (GdImage $image) => imagewebp($image, null, 90));
    }

    public static function gif(int $width = 400, int $height = 200): string
    {
        return self::encode(self::canvas($width, $height), fn (GdImage $image) => imagegif($image));
    }

    /**
     * PNG pequeño cuya cabecera declara otras dimensiones, como una «bomba de
     * descompresión»: la validación debe rechazarlo sin decodificarlo.
     */
    public static function pngDeclaring(int $width, int $height): string
    {
        $bytes = self::png(10, 10);
        // Firma (8) + largo (4) + «IHDR» (4): ancho y alto, luego el resto de IHDR (5) y su CRC.
        $ihdr = 'IHDR'.pack('NN', $width, $height).substr($bytes, 24, 5);

        return substr($bytes, 0, 12).$ihdr.pack('N', crc32($ihdr)).substr($bytes, 33);
    }

    /** Texto con nombre de imagen: el contenido real no es una imagen. */
    public static function fake(): string
    {
        return "<?php echo 'no soy una imagen';\n";
    }

    public static function upload(string $bytes, string $name = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * Color [r, g, b, alfa de GD 0–127] de un píxel de una imagen codificada.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function colorAt(string $bytes, int $x, int $y): array
    {
        $image = imagecreatefromstring($bytes);
        imagesavealpha($image, true);
        $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']];
    }

    /** Si un color es claramente rojo (tolera la compresión con pérdida). */
    public static function isRed(array $rgba): bool
    {
        return $rgba[0] > 180 && $rgba[1] < 80 && $rgba[2] < 80 && $rgba[3] < 64;
    }

    private static function canvas(int $width, int $height, bool $transparent = false): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        }

        imagefilledrectangle($image, 0, 0, intdiv($width, 4) - 1, intdiv($height, 4) - 1, imagecolorallocate($image, 255, 0, 0));

        return $image;
    }

    /** @param  callable(GdImage): bool  $write */
    private static function encode(GdImage $image, callable $write): string
    {
        ob_start();
        $write($image);

        return (string) ob_get_clean();
    }

    /**
     * Segmento APP1 «Exif» en TIFF little-endian: IFD0 con Make, Orientation y,
     * si se pide, el puntero a un IFD de GPS con latitud y longitud de Lima.
     */
    private static function exifSegment(int $orientation, bool $gps): string
    {
        $entry = fn (int $tag, int $type, int $count, string $value) => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

        $make = self::CAMERA."\0";
        $ifd0Count = $gps ? 3 : 2;
        // Cabecera TIFF (8) + número de entradas (2) + entradas (12 c/u) + siguiente IFD (4).
        $makeOffset = 8 + 2 + 12 * $ifd0Count + 4;
        $gpsOffset = $makeOffset + strlen($make);

        $ifd0 = pack('v', $ifd0Count)
            .$entry(0x010F, 2, strlen($make), pack('V', $makeOffset))
            .$entry(0x0112, 3, 1, pack('v', $orientation))
            .($gps ? $entry(0x8825, 4, 1, pack('V', $gpsOffset)) : '')
            .pack('V', 0);

        $gpsIfd = '';
        if ($gps) {
            $rationalsOffset = $gpsOffset + 2 + 12 * 4 + 4;
            $coordinate = fn (int $degrees, int $minutes) => pack('VVVVVV', $degrees, 1, $minutes, 1, 0, 1);
            $gpsIfd = pack('v', 4)
                .$entry(0x0001, 2, 2, "S\0")
                .$entry(0x0002, 5, 3, pack('V', $rationalsOffset))
                .$entry(0x0003, 2, 2, "W\0")
                .$entry(0x0004, 5, 3, pack('V', $rationalsOffset + 24))
                .pack('V', 0)
                .$coordinate(12, 2).$coordinate(77, 1);
        }

        $tiff = "II\x2A\x00".pack('V', 8).$ifd0.$make.$gpsIfd;
        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }
}

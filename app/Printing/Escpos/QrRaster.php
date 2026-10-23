<?php

namespace App\Printing\Escpos;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Matrix\MatrixInterface;
use Endroid\QrCode\QrCode;

/**
 * QR como imagen de 1 bit (GS v 0) para las impresoras que no lo dibujan con
 * su propio comando (spec 015, A-77). Sale de la matriz de endroid/qr-code,
 * sin GD, con la misma corrección M que el QR nativo.
 */
final class QrRaster
{
    /** Zona de silencio en módulos, a cada lado. */
    public const QUIET_ZONE = 2;

    /** Filas por comando: las impresoras baratas tienen búferes pequeños. */
    private const BAND_ROWS = 24;

    private function __construct(private readonly MatrixInterface $matrix, private readonly int $module) {}

    /** @param  int  $maxDots  ancho imprimible: si no cabe, se reduce el módulo */
    public static function make(string $data, int $module, int $maxDots): self
    {
        $matrix = (new MatrixFactory)->create(new QrCode(data: $data, errorCorrectionLevel: ErrorCorrectionLevel::Medium));
        $modules = $matrix->getBlockCount() + 2 * self::QUIET_ZONE;

        return new self($matrix, max(1, min($module, intdiv($maxDots, $modules))));
    }

    public function module(): int
    {
        return $this->module;
    }

    public function escpos(): string
    {
        $count = $this->matrix->getBlockCount();
        $size = ($count + 2 * self::QUIET_ZONE) * $this->module;
        $bytesPerRow = intdiv($size + 7, 8);

        $rows = [];
        for ($y = 0; $y < $size; $y++) {
            $row = array_fill(0, $bytesPerRow, 0);
            $matrixRow = intdiv($y, $this->module) - self::QUIET_ZONE;
            if ($matrixRow >= 0 && $matrixRow < $count) {
                for ($x = 0; $x < $size; $x++) {
                    $matrixColumn = intdiv($x, $this->module) - self::QUIET_ZONE;
                    if ($matrixColumn >= 0 && $matrixColumn < $count && $this->matrix->getBlockValue($matrixRow, $matrixColumn) === 1) {
                        $row[intdiv($x, 8)] |= 0x80 >> ($x % 8);
                    }
                }
            }
            $rows[] = pack('C*', ...$row);
        }

        $bytes = '';
        foreach (array_chunk($rows, self::BAND_ROWS) as $band) {
            $bytes .= "\x1Dv0\x00".pack('v', $bytesPerRow).pack('v', count($band)).implode('', $band);
        }

        return $bytes;
    }
}

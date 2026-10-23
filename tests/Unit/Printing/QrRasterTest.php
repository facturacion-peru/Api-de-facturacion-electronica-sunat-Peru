<?php

use App\Printing\Escpos\EscposBuilder;
use App\Printing\Escpos\QrRaster;
use App\Printing\PrintOptions;
use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Tests\Support\EscposText;

/** Spec 015, T006: el QR como imagen de 1 bit para las impresoras sin QR nativo. */
const QR_PAYLOAD = '20123456789|03|B001|151|7.90|51.80|2026-10-23|0|-|qYm2o0bTq1o8QeVYJ0k6xS8cXx0=|';

/**
 * Une las bandas GS v 0 en una sola imagen.
 *
 * @return array{width: int, height: int, bits: list<string>}
 */
function rasterOf(string $bytes): array
{
    $rows = [];
    $width = null;
    $offset = 0;
    while (($pos = strpos($bytes, "\x1Dv0\x00", $offset)) !== false) {
        ['x' => $x, 'y' => $y] = unpack('vx/vy', substr($bytes, $pos + 4, 4));
        expect($y)->toBeLessThanOrEqual(24);
        $width ??= $x;
        expect($x)->toBe($width);
        foreach (str_split(substr($bytes, $pos + 8, $x * $y), $x) as $row) {
            $rows[] = $row;
        }
        $offset = $pos + 8 + $x * $y;
    }

    return ['width' => $width * 8, 'height' => count($rows), 'bits' => $rows];
}

function isBlack(array $raster, int $x, int $y): bool
{
    return (ord($raster['bits'][$y][intdiv($x, 8)]) >> (7 - $x % 8) & 1) === 1;
}

it('la imagen cabe en el papel y su ancho es múltiplo de 8 puntos', function (int $width) {
    $options = new PrintOptions($width, 'image');
    $raster = rasterOf(QrRaster::make(QR_PAYLOAD, $options->qrModule(), $options->dots())->escpos());

    expect($raster['width'] % 8)->toBe(0)
        ->and($raster['width'])->toBeLessThanOrEqual($options->dots())
        ->and($raster['height'])->toBeGreaterThan(24);
})->with([58, 80]);

it('la imagen reproduce módulo por módulo la matriz del QR', function (int $width) {
    $options = new PrintOptions($width, 'image');
    $qr = QrRaster::make(QR_PAYLOAD, $options->qrModule(), $options->dots());
    $raster = rasterOf($qr->escpos());
    $matrix = (new MatrixFactory)->create(new QrCode(data: QR_PAYLOAD, errorCorrectionLevel: ErrorCorrectionLevel::Medium));
    $count = $matrix->getBlockCount();
    $module = $qr->module();
    $quiet = QrRaster::QUIET_ZONE * $module;

    expect($raster['height'])->toBe(($count + 2 * QrRaster::QUIET_ZONE) * $module);
    for ($row = 0; $row < $count; $row++) {
        for ($column = 0; $column < $count; $column++) {
            $center = intdiv($module, 2);
            expect(isBlack($raster, $quiet + $column * $module + $center, $quiet + $row * $module + $center))
                ->toBe($matrix->getBlockValue($row, $column) === 1);
        }
    }
    // Zona de silencio en blanco.
    expect(isBlack($raster, 0, 0))->toBeFalse()->and(isBlack($raster, $quiet - 1, $quiet - 1))->toBeFalse();
})->with([58, 80]);

it('reduce el módulo si el QR no cabe en el ancho', function () {
    $qr = QrRaster::make(str_repeat('A', 600), 6, 384);

    expect(rasterOf($qr->escpos())['width'])->toBeLessThanOrEqual(384)
        ->and($qr->module())->toBeLessThan(6);
});

it('el builder imprime el QR como imagen centrada si la impresora no tiene QR nativo', function () {
    $bytes = (new EscposBuilder(new PrintOptions(58, 'image')))->qr(QR_PAYLOAD)->bytes();

    expect($bytes)->toContain("\x1Ba\x01\x1Dv0\x00")->not->toContain("\x1D(k")
        ->and(EscposText::qrs($bytes))->toBe([]);
    expect(EscposText::decode($bytes))->toContain('[IMG ');
});

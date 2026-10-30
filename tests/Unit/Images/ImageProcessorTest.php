<?php

use App\Images\ImageProcessor;
use App\Images\InvalidImage;
use Tests\Support\TestImages;

/*
 * Foto del producto (spec 016, A-81): se vuelve a codificar en WebP, derecha
 * según el EXIF, sin metadatos, en 800 y 200 px sin agrandar.
 */

$process = fn (string $bytes) => (new ImageProcessor)->process($bytes);
$size = fn (string $bytes) => array_slice(getimagesizefromstring($bytes), 0, 3);

it('devuelve la grande y la miniatura en WebP', function () use ($process, $size) {
    $image = $process(TestImages::jpeg(1600, 800));

    expect($size($image->large))->toBe([800, 400, IMAGETYPE_WEBP])
        ->and($size($image->thumb))->toBe([200, 100, IMAGETYPE_WEBP]);
});

it('mantiene la proporción de las fotos verticales', function () use ($process, $size) {
    $image = $process(TestImages::jpeg(1000, 2000));

    expect($size($image->large))->toBe([400, 800, IMAGETYPE_WEBP])
        ->and($size($image->thumb))->toBe([100, 200, IMAGETYPE_WEBP]);
});

it('no agranda las imágenes pequeñas', function () use ($process, $size) {
    $image = $process(TestImages::png(300, 150));

    expect($size($image->large))->toBe([300, 150, IMAGETYPE_WEBP])
        ->and($size($image->thumb))->toBe([200, 100, IMAGETYPE_WEBP]);

    expect($size($process(TestImages::webp(120, 60))->thumb))->toBe([120, 60, IMAGETYPE_WEBP]);
});

it('endereza la foto según la orientación EXIF', function (int $orientation, array $dimensions, array $redAt) use ($process, $size) {
    $large = $process(TestImages::jpeg(400, 200, orientation: $orientation))->large;

    expect(array_slice($size($large), 0, 2))->toBe($dimensions)
        ->and(TestImages::isRed(TestImages::colorAt($large, ...$redAt)))->toBeTrue()
        // La esquina opuesta no es roja: no basta con que haya rojo en algún lado.
        ->and(TestImages::isRed(TestImages::colorAt($large, $dimensions[0] - 1 - $redAt[0], $dimensions[1] - 1 - $redAt[1])))->toBeFalse();
})->with([
    'sin girar (1)' => [1, [400, 200], [10, 10]],
    'espejo (2)' => [2, [400, 200], [389, 10]],
    'de cabeza (3)' => [3, [400, 200], [389, 189]],
    'girada a la derecha (6)' => [6, [200, 400], [189, 10]],
    'girada a la izquierda (8)' => [8, [200, 400], [10, 389]],
]);

it('no conserva EXIF, cámara ni GPS', function () use ($process) {
    $image = $process(TestImages::jpeg(orientation: 6, gps: true));

    foreach ([$image->large, $image->thumb] as $bytes) {
        expect($bytes)->toStartWith('RIFF')
            ->not->toContain('Exif')
            ->not->toContain(TestImages::CAMERA)
            ->not->toContain('EXIF');
    }
});

it('conserva la transparencia de un PNG', function () use ($process) {
    $image = $process(TestImages::png(400, 200));

    expect(TestImages::colorAt($image->large, 390, 190)[3])->toBe(127)
        ->and(TestImages::colorAt($image->thumb, 195, 95)[3])->toBe(127)
        ->and(TestImages::isRed(TestImages::colorAt($image->large, 10, 10)))->toBeTrue();
});

it('rechaza lo que no es JPEG, PNG ni WebP', function (string $bytes) use ($process) {
    expect(fn () => $process($bytes))->toThrow(InvalidImage::class);
})->with([
    'texto' => [TestImages::fake()],
    'GIF' => [fn () => TestImages::gif()],
    'JPEG cortado' => [fn () => substr(TestImages::jpeg(), 0, 200)],
    'vacío' => [''],
]);

it('rechaza dimensiones mayores a 4000 px sin decodificar la imagen', function () use ($process) {
    expect(fn () => $process(TestImages::pngDeclaring(20000, 20000)))->toThrow(InvalidImage::class);
});

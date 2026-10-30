<?php

use App\Images\InvalidImage;
use App\Images\LogoProcessor;
use Tests\Support\TestImages;

/*
 * Logo de la empresa (spec 016, HU-4): PNG con alfa para el PDF, hasta 800 px
 * de ancho y sin metadatos.
 */

$process = fn (string $bytes) => (new LogoProcessor)->process($bytes);

it('conserva la transparencia de un PNG', function () use ($process) {
    $logo = $process(TestImages::png(400, 200));

    expect(array_slice(getimagesizefromstring($logo), 0, 3))->toBe([400, 200, IMAGETYPE_PNG])
        ->and(TestImages::colorAt($logo, 390, 190)[3])->toBe(127)
        ->and(TestImages::isRed(TestImages::colorAt($logo, 10, 10)))->toBeTrue();
});

it('reduce a 800 px de ancho con la proporción', function () use ($process) {
    expect(array_slice(getimagesizefromstring($process(TestImages::jpeg(1600, 400))), 0, 3))->toBe([800, 200, IMAGETYPE_PNG])
        ->and(array_slice(getimagesizefromstring($process(TestImages::png(300, 900))), 0, 2))->toBe([300, 900]);
});

it('endereza un JPEG y no conserva sus metadatos', function () use ($process) {
    $logo = $process(TestImages::jpeg(400, 200, orientation: 6, gps: true));

    expect(array_slice(getimagesizefromstring($logo), 0, 2))->toBe([200, 400])
        ->and(TestImages::isRed(TestImages::colorAt($logo, 189, 10)))->toBeTrue()
        ->and($logo)->not->toContain('Exif')->not->toContain(TestImages::CAMERA);
});

it('rechaza lo que no es PNG ni JPEG', function (string $bytes) use ($process) {
    expect(fn () => $process($bytes))->toThrow(InvalidImage::class);
})->with([
    'texto' => [TestImages::fake()],
    'WebP' => [fn () => TestImages::webp()],
    'enorme' => [fn () => TestImages::pngDeclaring(20000, 100)],
]);

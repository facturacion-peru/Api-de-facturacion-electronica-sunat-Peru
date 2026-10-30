<?php

use Tests\Support\TestImages;

/*
 * Las imágenes de prueba de la spec 016 son lo que dicen ser: si el EXIF
 * armado a mano no se leyera, las pruebas de orientación no probarían nada.
 */

it('genera JPEG, PNG, WebP y GIF reales con sus dimensiones', function (string $bytes, int $type) {
    $info = getimagesizefromstring($bytes);

    expect($info[0])->toBe(400)->and($info[1])->toBe(200)->and($info[2])->toBe($type);
})->with([
    'jpeg' => [fn () => TestImages::jpeg(), IMAGETYPE_JPEG],
    'png' => [fn () => TestImages::png(), IMAGETYPE_PNG],
    'webp' => [fn () => TestImages::webp(), IMAGETYPE_WEBP],
    'gif' => [fn () => TestImages::gif(), IMAGETYPE_GIF],
]);

it('la esquina superior izquierda es roja y el resto blanco', function () {
    $jpeg = TestImages::jpeg();

    expect(TestImages::isRed(TestImages::colorAt($jpeg, 10, 10)))->toBeTrue()
        ->and(TestImages::isRed(TestImages::colorAt($jpeg, 390, 190)))->toBeFalse();
});

it('arma un EXIF legible con orientación, cámara y GPS', function () {
    $exif = exif_read_data('data://image/jpeg;base64,'.base64_encode(TestImages::jpeg(orientation: 6, gps: true)));

    expect($exif['Orientation'])->toBe(6)
        ->and($exif['Make'])->toBe(TestImages::CAMERA)
        ->and($exif['GPSLatitudeRef'])->toBe('S')
        ->and($exif['GPSLatitude'])->toBe(['12/1', '2/1', '0/1'])
        ->and($exif['GPSLongitudeRef'])->toBe('W');

    expect(imagecreatefromstring(TestImages::jpeg(orientation: 8)))->toBeInstanceOf(GdImage::class);
});

it('el PNG conserva la transparencia fuera de la esquina roja', function () {
    $png = TestImages::png();

    expect(TestImages::colorAt($png, 390, 190)[3])->toBe(127)
        ->and(TestImages::isRed(TestImages::colorAt($png, 10, 10)))->toBeTrue();
});

it('el PNG «bomba» declara dimensiones que no tiene', function () {
    $bytes = TestImages::pngDeclaring(20000, 20000);

    expect(getimagesizefromstring($bytes))->toMatchArray([0 => 20000, 1 => 20000])
        ->and(strlen($bytes))->toBeLessThan(1024);
});

it('el archivo falso no es una imagen', function () {
    expect(@getimagesizefromstring(TestImages::fake()))->toBeFalse()
        ->and(TestImages::upload(TestImages::fake(), 'foto.jpg')->getClientOriginalName())->toBe('foto.jpg');
});

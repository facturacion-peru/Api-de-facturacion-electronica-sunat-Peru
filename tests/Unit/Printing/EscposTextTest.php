<?php

use Tests\Support\EscposText;

/** Spec 015, T003: el decodificador de pruebas de ESC/POS. */
it('convierte el texto de CP850 y marca los estilos', function () {
    $bytes = "\x1B@\x1Bt\x02\x1Ba\x01\x1BE\x01".mb_convert_encoding('Año', 'CP850', 'UTF-8')."\n\x1BE\x00\x1D!\x11TOTAL\n\x1D!\x00\x1Bd\x02\x1DVB\x00";

    expect(EscposText::decode($bytes))->toBe("[B]Año\n[/B][2x]TOTAL\n[/2x]\n\n[CUT]")
        ->and(EscposText::lines($bytes))->toBe(['Año', 'TOTAL']);
});

it('reconoce el QR nativo y las imágenes', function () {
    $data = 'RUC|03|B001|';
    $store = "\x1D(k".pack('v', strlen($data) + 3).'1P0'.$data;
    $image = "\x1Dv0\x00".pack('v', 2).pack('v', 3).str_repeat("\xFF", 6);
    $bytes = "\x1D(k\x04\x001A2\x00\x1D(k\x03\x001C\x05\x1D(k\x03\x001E1".$store."\x1D(k\x03\x001Q0\n".$image."\n";

    expect(EscposText::decode($bytes))->toBe("[QR:{$data}]\n[IMG 16x3]\n")
        ->and(EscposText::qrs($bytes))->toBe([$data]);
});

it('falla ante un comando o un byte de control que no conoce', function (string $bytes) {
    EscposText::decode($bytes);
})->with([
    'ESC desconocido' => "\x1BZ",
    'GS desconocido' => "\x1DZ",
    'control suelto' => "A\x07B",
    'comando cortado' => "\x1Bt",
])->throws(RuntimeException::class);

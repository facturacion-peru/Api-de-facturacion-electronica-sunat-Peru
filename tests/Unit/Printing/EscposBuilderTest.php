<?php

use App\Printing\Escpos\Align;
use App\Printing\Escpos\EscposBuilder;
use App\Printing\PrintOptions;
use Tests\Support\EscposText;

/** Spec 015, T004: bytes ESC/POS exactos, texto en CP850 y ajuste de línea. */
const ESCPOS_INIT = "\x1B@\x1Bt\x02";

function builder(int $width = 58, string $qr = 'native'): EscposBuilder
{
    return new EscposBuilder(new PrintOptions($width, $qr));
}

/** Bytes después de la inicialización. */
function body(EscposBuilder $builder): string
{
    $bytes = $builder->bytes();
    expect(substr($bytes, 0, strlen(ESCPOS_INIT)))->toBe(ESCPOS_INIT);

    return substr($bytes, strlen(ESCPOS_INIT));
}

it('inicia la impresora con la tabla PC850', function () {
    expect(builder()->bytes())->toBe(ESCPOS_INIT);
});

it('conoce el ancho de cada papel', function (int $width, int $columns, int $dots, int $module) {
    $options = new PrintOptions($width, 'native');

    expect($options->columns())->toBe($columns)
        ->and($options->dots())->toBe($dots)
        ->and($options->qrModule())->toBe($module);
})->with([[58, 32, 384, 5], [80, 48, 576, 6]]);

it('escribe texto sin comandos cuando no cambia el estilo', function () {
    expect(body(builder()->text('Hola')))->toBe("Hola\n");
});

it('cambia alineación, negrita y tamaño solo cuando hace falta', function () {
    $bytes = body(builder()
        ->text('A', Align::Center, bold: true)
        ->text('B', Align::Center, bold: true)
        ->text('C', big: true)
        ->text('D'));

    expect($bytes)->toBe("\x1Ba\x01\x1BE\x01A\nB\n\x1Ba\x00\x1BE\x00\x1D!\x11C\n\x1D!\x00D\n");
});

it('alinea a la derecha', function () {
    expect(body(builder()->text('X', Align::Right)))->toBe("\x1Ba\x02X\n");
});

it('avanza y corta el papel', function () {
    expect(body(builder()->feedAndCut()))->toBe("\x1Bd\x04\x1DVB\x00");
});

it('dibuja el QR nativo modelo 2 con corrección M y el módulo del papel', function (int $width, string $module) {
    $data = 'RUC|03|B001|151|';
    $expected = "\x1Ba\x01"
        ."\x1D(k\x04\x001A2\x00"
        ."\x1D(k\x03\x001C".$module
        ."\x1D(k\x03\x001E1"
        ."\x1D(k".pack('v', strlen($data) + 3).'1P0'.$data
        ."\x1D(k\x03\x001Q0\n";

    expect(body(builder($width)->qr($data)))->toBe($expected);
})->with([[58, "\x05"], [80, "\x06"]]);

it('calcula la longitud del QR en dos bytes', function (int $size, string $lengthBytes) {
    $bytes = body(builder()->qr(str_repeat('a', $size)));

    expect($bytes)->toContain("\x1D(k".$lengthBytes.'1P0');
})->with([[100, "\x67\x00"], [300, "\x2F\x01"]]);

it('convierte tildes, eñes y signos a CP850', function () {
    $text = 'áéíóú ÁÉÍÓÚ ñÑ ¿¡ «» ü °';

    expect(body(builder()->text($text)))->toBe(mb_convert_encoding($text, 'CP850', 'UTF-8')."\n")
        ->and(EscposText::lines(builder()->text($text)->bytes()))->toBe([$text]);
});

it('reemplaza los signos tipográficos y omite lo que no tiene equivalente', function (string $input, string $printed) {
    expect(EscposText::lines(builder()->text($input)->bytes()))->toBe([$printed]);
})->with([
    'raya' => ['PRUEBAS — SIN VALOR', 'PRUEBAS - SIN VALOR'],
    'menos y por' => ['2 × S/ 3 −1', '2 x S/ 3 -1'],
    'comillas' => ['“Doña” ‘Pepa’', '"Doña" \'Pepa\''],
    'puntos' => ['Etc…', 'Etc...'],
    'emoji' => ['Pan 🍞 dulce', 'Pan dulce'],
    'euro' => ['Precio €5', 'Precio 5'],
]);

it('no deja pasar comandos dentro de los textos', function () {
    $bytes = body(builder()->text("Producto\x1B@\x1DV\x00malo\tcon\r\nsaltos"));

    expect($bytes)->toBe("Producto @ V malo con saltos\n");
});

it('pone el importe a la derecha de la fila', function () {
    $lines = EscposText::lines(builder()->row('Arroz', 'S/ 5.00')->bytes());

    expect($lines)->toBe(['Arroz'.str_repeat(' ', 32 - 5 - 7).'S/ 5.00']);
});

it('parte por palabras un texto largo y deja el importe en la última línea', function () {
    $name = 'Aceite vegetal de soya Primor botella de un litro con tapa rosca';
    $lines = EscposText::lines(builder()->row($name, 'S/ 12.50')->bytes());

    expect($lines)->toBe([
        'Aceite vegetal de soya Primor',
        'botella de un litro con tapa',
        'rosca'.str_repeat(' ', 32 - 5 - 8).'S/ 12.50',
    ]);
});

it('si el importe no cabe en la última línea, va en una línea propia', function () {
    $lines = EscposText::lines(builder()->row('Galletas de chocolate rellenas x', 'S/ 1,234.50')->bytes());

    expect($lines)->toBe(['Galletas de chocolate rellenas x', str_pad('S/ 1,234.50', 32, ' ', STR_PAD_LEFT)]);
});

it('parte las palabras más largas que la línea', function () {
    $lines = EscposText::lines(builder()->text(str_repeat('A', 70))->bytes());

    expect($lines)->toBe([str_repeat('A', 32), str_repeat('A', 32), str_repeat('A', 6)]);
});

it('a doble tamaño la línea tiene la mitad de caracteres', function () {
    $lines = EscposText::lines(builder()->row('TOTAL', 'S/ 1,234.50', big: true)->bytes());

    expect($lines)->toBe(['TOTAL', '     S/ 1,234.50']);
});

it('muestra etiqueta y valor en una fila, o el valor debajo si no cabe', function () {
    $lines = EscposText::lines(builder()
        ->pair('Cliente', 'María')
        ->pair('Dirección', 'Av. Los Próceres de la Independencia 1234, San Juan de Lurigancho')
        ->bytes());

    expect($lines)->toBe([
        'Cliente'.str_repeat(' ', 32 - 7 - 5).'María',
        'Dirección',
        '  Av. Los Próceres de la',
        '  Independencia 1234, San Juan',
        '  de Lurigancho',
    ]);
});

it('la regla ocupa el ancho completo', function (int $width, int $columns) {
    expect(EscposText::lines(builder($width)->rule()->bytes()))->toBe([str_repeat('-', $columns)]);
})->with([[58, 32], [80, 48]]);

it('ninguna línea supera el ancho del papel con textos de 100 caracteres', function (int $width, int $columns) {
    $long = 'Razón social de una empresa con un nombre larguísimo y además una dirección extensa número 1234 Lima';
    $word = str_repeat('X', 100);
    expect(mb_strlen($long))->toBe(100);

    $bytes = builder($width)
        ->text($long)->text($long, Align::Center, bold: true)->text($word)
        ->text($long, big: true)
        ->row($long, 'S/ 99,999.99')->row($word, 'S/ 1.00')->row($long, 'S/ 99,999.99', big: true)
        ->pair('Dirección', $long)->pair($long, 'valor')
        ->rule()
        ->bytes();

    $printed = 0;
    $big = false;
    foreach (explode("\n", EscposText::decode($bytes)) as $line) {
        $big = str_contains($line, '[2x]') || ($big && ! str_contains($line, '[/2x]'));
        $plain = preg_replace('/\[(\/?B|\/?2x)\]/', '', $line);
        expect(mb_strlen($plain))->toBeLessThanOrEqual($big ? intdiv($columns, 2) : $columns);
        $printed++;
    }
    expect($printed)->toBeGreaterThan(10);
})->with([[58, 32], [80, 48]]);

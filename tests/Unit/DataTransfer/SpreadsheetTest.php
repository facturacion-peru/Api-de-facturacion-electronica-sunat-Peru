<?php

use App\DataTransfer\Spreadsheet;
use App\DataTransfer\UnreadableFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/** Spec 014, T007: lectura y escritura de XLSX y CSV. */
function tempPath(string $extension): string
{
    return sys_get_temp_dir().'/spreadsheet-test-'.bin2hex(random_bytes(6)).'.'.$extension;
}

/** @param list<list<Cell>> $rows */
function xlsxWith(array $rows): string
{
    $path = tempPath('xlsx');
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    foreach ($rows as $cells) {
        $writer->addRow(new Row($cells));
    }
    $writer->close();

    return $path;
}

/** @return list<array{int, list<string>}> */
function readAll(string $path): array
{
    return iterator_to_array(Spreadsheet::rows($path), false);
}

it('lee el valor guardado de una fórmula, nunca la fórmula', function () {
    $path = xlsxWith([
        [new Cell\StringCell('codigo', null), new Cell\StringCell('precio_venta', null)],
        [new Cell\StringCell('P-1', null), new Cell\FormulaCell('=1+2', null)],
    ]);
    // Como lo guarda Excel: la fórmula y su último valor calculado.
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->addFromString('xl/worksheets/sheet1.xml', preg_replace('#<f>1\+2</f></c>#', '<f>1+2</f><v>3</v></c>', $xml, 1, $count));
    $zip->close();
    expect($count)->toBe(1);

    expect(readAll($path))->toBe([[1, ['codigo', 'precio_venta']], [2, ['P-1', '3']]]);
});

it('convierte flotantes sin artefactos, fechas y booleanos', function () {
    $date = (new Style)->setFormat('yyyy-mm-dd');
    $path = xlsxWith([
        [new Cell\NumericCell(3.8, null), new Cell\NumericCell(0.1 + 0.2, null), new Cell\NumericCell(20123456789, null),
            new Cell\DateTimeCell(new DateTimeImmutable('2026-12-31'), $date), new Cell\BooleanCell(true, null)],
    ]);

    expect(readAll($path)[0][1])->toBe(['3.8', '0.3', '20123456789', '2026-12-31', 'si']);
});

it('conserva el número de fila de Excel aunque haya filas vacías', function () {
    $path = xlsxWith([
        [new Cell\StringCell('codigo', null)],
        [new Cell\EmptyCell(null, null)],
        [new Cell\StringCell('P-1', null)],
    ]);

    expect(readAll($path))->toBe([[1, ['codigo']], [3, ['P-1']]]);
});

it('lee un CSV de Excel en Windows-1252 con punto y coma', function () {
    $path = tempPath('csv');
    file_put_contents($path, mb_convert_encoding("codigo;nombre\nP-1;Azúcar rubia\n", 'Windows-1252', 'UTF-8'));

    expect(readAll($path))->toBe([[1, ['codigo', 'nombre']], [2, ['P-1', 'Azúcar rubia']]]);
});

it('lee un CSV en UTF-8 con BOM y quita el apóstrofo de la exportación', function () {
    $path = tempPath('csv');
    file_put_contents($path, "\xEF\xBB\xBFcodigo,nombre\nP-1,'=Oferta\n");

    expect(readAll($path))->toBe([[1, ['codigo', 'nombre']], [2, ['P-1', '=Oferta']]]);
});

it('rechaza un archivo que no es XLSX ni CSV', function () {
    $path = tempPath('xlsx');
    file_put_contents($path, "PK\x03\x04no es un zip");
    expect(fn () => readAll($path))->toThrow(UnreadableFile::class);

    $binary = tempPath('csv');
    file_put_contents($binary, "\x00\x01\x02");
    expect(fn () => readAll($binary))->toThrow(UnreadableFile::class);
});

it('en XLSX escribe los textos como texto y los importes como números', function () {
    $sheet = new Spreadsheet(Spreadsheet::XLSX);
    $sheet->sheet('productos', ['nombre' => Spreadsheet::TEXT, 'total' => Spreadsheet::MONEY]);
    $sheet->row(['=HYPERLINK("http://x")', '-12.50']);
    $file = $sheet->finish('productos-2026-10-07');

    $zip = new ZipArchive;
    $zip->open($file->path);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($file->filename)->toBe('productos-2026-10-07.xlsx')
        ->and($xml)->not->toContain('<f>')
        ->and($xml)->toContain('t="inlineStr"><is><t>=HYPERLINK(&quot;http://x&quot;)</t>')
        ->and($xml)->toMatch('/<c r="B2" s="\d+"><v>-12.5<\/v><\/c>/')
        ->and(readAll($file->path)[1][1])->toBe(['=HYPERLINK("http://x")', '-12.5']);

    $file->delete();
    expect(file_exists(dirname($file->path)))->toBeFalse();
});

it('en CSV neutraliza los textos con fórmula y deja intactos los importes negativos', function () {
    $sheet = new Spreadsheet(Spreadsheet::CSV);
    $sheet->sheet('ventas', ['nombre' => Spreadsheet::TEXT, 'total' => Spreadsheet::MONEY]);
    $sheet->row(['=HYPERLINK("http://x")', '-12.50']);
    $sheet->row(['@SUMA', '3.00']);
    $file = $sheet->finish('ventas');

    $content = file_get_contents($file->path);
    expect($file->filename)->toBe('ventas.csv')
        ->and($content)->toStartWith("\xEF\xBB\xBF")
        ->and($content)->toContain("\"'=HYPERLINK(\"\"http://x\"\")\",-12.50")
        ->and($content)->toContain("'@SUMA,3.00");
    $file->delete();
});

it('entrega varias hojas CSV en un ZIP, una por archivo', function () {
    $sheet = new Spreadsheet(Spreadsheet::CSV);
    $sheet->sheet('documentos', ['numero' => Spreadsheet::TEXT]);
    $sheet->row(['B001-1']);
    $sheet->sheet('lineas', ['numero' => Spreadsheet::TEXT]);
    $sheet->row(['B001-1']);
    $file = $sheet->finish('ventas');

    $zip = new ZipArchive;
    $zip->open($file->path);
    expect($file->filename)->toBe('ventas.zip')
        ->and([$zip->getNameIndex(0), $zip->getNameIndex(1)])->toBe(['documentos.csv', 'lineas.csv'])
        ->and($zip->getFromName('lineas.csv'))->toContain('B001-1');
    $zip->close();
    $file->delete();
});

it('exporta y relee sin perder datos: textos con tildes, ceros a la izquierda y decimales', function (string $format, string $price, string $zero) {
    $sheet = new Spreadsheet($format);
    $sheet->sheet('productos', ['codigo' => Spreadsheet::TEXT, 'nombre' => Spreadsheet::TEXT, 'precio' => Spreadsheet::MONEY, 'costo' => Spreadsheet::COST]);
    $sheet->row(['0012', 'Azúcar «rubia» ñ', '3.80', '1.2345']);
    $sheet->row(['-P', '+Promo', '0.00', null]);
    $file = $sheet->finish('productos');

    // XLSX devuelve números; CSV, el texto tal como se escribió.
    expect(readAll($file->path))->toBe([
        [1, ['codigo', 'nombre', 'precio', 'costo']],
        [2, ['0012', 'Azúcar «rubia» ñ', $price, '1.2345']],
        [3, ['-P', '+Promo', $zero, '']],
    ]);
    $file->delete();
})->with([
    'xlsx' => [Spreadsheet::XLSX, '3.8', '0'],
    'csv' => [Spreadsheet::CSV, '3.80', '0.00'],
]);

<?php

namespace App\DataTransfer;

use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;
use ZipArchive;

/**
 * Lectura y escritura de XLSX y CSV para la spec 014, sobre OpenSpout.
 *
 * Al leer, cada celda se entrega como texto: de una fórmula se toma el valor
 * guardado (nunca se evalúa), los números sin artefactos de coma flotante y
 * las fechas como AAAA-MM-DD.
 *
 * Al escribir, los textos van siempre como celdas de texto (en XLSX no se
 * convierten en fórmula); en CSV, los que una hoja de cálculo tomaría por
 * fórmula llevan un apóstrofo delante (RF-011), que la lectura quita.
 */
final class Spreadsheet
{
    public const XLSX = 'xlsx';

    public const CSV = 'csv';

    /** Tipos de columna al escribir. */
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const QUANTITY = 'quantity';

    public const COST = 'cost';

    private const NUMBER_FORMATS = [self::MONEY => '0.00', self::QUANTITY => '0.000', self::COST => '0.0000'];

    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var list<array{name: string, path: string}> */
    private array $csvFiles = [];

    private XlsxWriter|CsvWriter|null $writer = null;

    private string $directory;

    /** @var list<string> */
    private array $types = [];

    /** @var array<string, Style> */
    private array $styles = [];

    private bool $firstSheet = true;

    public function __construct(private readonly string $format)
    {
        $this->directory = sys_get_temp_dir().'/sunat-export-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    // ── Escritura ────────────────────────────────────────────────────────

    /**
     * Empieza una hoja (en CSV, un archivo) con sus encabezados.
     *
     * @param  array<string, string>  $columns  encabezado => tipo (TEXT, MONEY, QUANTITY, COST)
     * @param  list<string>  $textColumns  encabezados con formato de texto en las filas vacías (plantillas)
     */
    public function sheet(string $name, array $columns, array $textColumns = [], int $blankRows = 0): void
    {
        $this->types = array_values($columns);

        if ($this->format === self::CSV) {
            $this->writer?->close();
            $path = "{$this->directory}/{$name}.csv";
            $this->writer = new CsvWriter;
            $this->writer->openToFile($path);
            $this->csvFiles[] = ['name' => "{$name}.csv", 'path' => $path];
        } else {
            if ($this->writer === null) {
                $this->writer = new XlsxWriter;
                $this->writer->openToFile("{$this->directory}/export.xlsx");
            }
            $sheet = $this->firstSheet ? $this->writer->getCurrentSheet() : $this->writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $this->firstSheet = false;
        }

        $bold = (new Style)->setFontBold();
        $this->writer->addRow(new Row(array_map(fn (string $header) => new Cell\StringCell($header, $bold), array_keys($columns))));

        if ($blankRows > 0 && $this->writer instanceof XlsxWriter) {
            $headers = array_keys($columns);
            $text = (new Style)->setFormat('@');
            $blank = new Row(array_map(fn (string $header) => in_array($header, $textColumns, true)
                ? new Cell\EmptyCell(null, $text)
                : new Cell\EmptyCell(null, null), $headers));
            for ($i = 0; $i < $blankRows; $i++) {
                $this->writer->addRow($blank);
            }
        }
    }

    /** @param  list<string|int|float|null>  $values  importes como cadenas decimales */
    public function row(array $values): void
    {
        $cells = [];
        foreach (array_values($values) as $index => $value) {
            $cells[] = $this->cell($value, $this->types[$index] ?? self::TEXT);
        }
        $this->writer->addRow(new Row($cells));
    }

    /** Cierra el archivo; varias hojas en CSV se entregan en un ZIP. */
    public function finish(string $basename): ExportFile
    {
        $this->writer?->close();

        if ($this->format === self::XLSX) {
            return new ExportFile("{$this->directory}/export.xlsx", "{$basename}.xlsx", self::XLSX_MIME);
        }

        if (count($this->csvFiles) === 1) {
            return new ExportFile($this->csvFiles[0]['path'], "{$basename}.csv", 'text/csv; charset=UTF-8');
        }

        $zipPath = "{$this->directory}/export.zip";
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($this->csvFiles as $file) {
            $zip->addFile($file['path'], $file['name']);
        }
        $zip->close();

        return new ExportFile($zipPath, "{$basename}.zip", 'application/zip');
    }

    /** Neutraliza un texto que una hoja de cálculo tomaría por fórmula (RF-011). */
    public static function safeText(string $value): string
    {
        return $value !== '' && in_array($value[0], self::FORMULA_STARTS, true) ? "'".$value : $value;
    }

    private function cell(string|int|float|null $value, string $type): Cell
    {
        if ($value === null || $value === '') {
            return new Cell\EmptyCell(null, null);
        }

        if ($type !== self::TEXT) {
            if ($this->format === self::CSV) {
                return new Cell\StringCell((string) $value, null);
            }

            return new Cell\NumericCell((float) $value, $this->numberStyle($type));
        }

        $text = (string) $value;

        return new Cell\StringCell($this->format === self::CSV ? self::safeText($text) : $text, null);
    }

    private function numberStyle(string $type): Style
    {
        return $this->styles[$type] ??= (new Style)->setFormat(self::NUMBER_FORMATS[$type]);
    }

    // ── Lectura ──────────────────────────────────────────────────────────

    /**
     * Filas no vacías de la primera hoja: [número de fila como en Excel, celdas como texto].
     *
     * @return Generator<int, array{int, list<string>}>
     *
     * @throws UnreadableFile
     */
    public static function rows(string $path): Generator
    {
        $head = (string) file_get_contents($path, length: 4);
        $csvCopy = null;

        try {
            if (str_starts_with($head, "PK\x03\x04")) {
                $options = new XlsxReaderOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
                $reader = new XlsxReader($options);
                $reader->open($path);
            } else {
                [$csvCopy, $delimiter] = self::normalizeCsv($path);
                $options = new CsvReaderOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
                $options->FIELD_DELIMITER = $delimiter;
                $reader = new CsvReader($options);
                $reader->open($csvCopy);
            }
        } catch (UnreadableFile $e) {
            throw $e;
        } catch (Throwable) {
            throw new UnreadableFile('No se pudo leer el archivo. Usa un XLSX o CSV con el formato de la plantilla.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = array_map(self::cellToString(...), $row->getCells());
                    if (implode('', $cells) !== '') {
                        yield [$number, $cells];
                    }
                }

                break; // solo la primera hoja
            }
        } catch (Throwable $e) {
            throw new UnreadableFile('No se pudo leer el archivo. Usa un XLSX o CSV con el formato de la plantilla.', previous: $e);
        } finally {
            $reader->close();
            if ($csvCopy !== null) {
                @unlink($csvCopy);
            }
        }
    }

    /** Celda como texto: valor guardado de las fórmulas, números exactos y fechas AAAA-MM-DD. */
    public static function cellToString(Cell $cell): string
    {
        $value = $cell->getValue();

        // OpenSpout también lee como «fórmula» (sin valor guardado) un texto
        // que empieza con «=»: en ese caso el dato es el propio texto.
        if ($cell instanceof Cell\FormulaCell && $cell->getComputedValue() !== null) {
            $value = $cell->getComputedValue();
        }

        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'si' : 'no',
            is_int($value) => (string) $value,
            is_float($value) => self::floatToString($value),
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_string($value) => $value,
            default => '',
        };

        $text = trim($text);

        // El apóstrofo que pone la exportación CSV (RF-011) no es parte del dato.
        if (strlen($text) > 1 && $text[0] === "'" && in_array($text[1], self::FORMULA_STARTS, true)) {
            $text = substr($text, 1);
        }

        return $text;
    }

    private static function floatToString(float $value): string
    {
        $text = sprintf('%.10F', $value);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * Copia el CSV en UTF-8 y sin BOM (Excel en Windows guarda en
     * Windows-1252) y detecta el separador por la primera línea.
     *
     * @return array{string, string}
     */
    private static function normalizeCsv(string $path): array
    {
        $content = (string) file_get_contents($path);

        if (str_contains($content, "\0")) {
            throw new UnreadableFile('El archivo no es un XLSX ni un CSV.');
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        $copy = tempnam(sys_get_temp_dir(), 'sunat-import-');
        file_put_contents($copy, $content);

        return [$copy, $delimiter];
    }
}

<?php

namespace Tests\Support;

use App\DataTransfer\Spreadsheet;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ZipArchive;

/** Lee en las pruebas los archivos que devuelve una exportación (spec 014). */
final class SpreadsheetFile
{
    /**
     * Hojas del archivo descargado: nombre => filas, cada una como
     * encabezado => valor (texto, como lo lee la importación).
     *
     * @return array<string, list<array<string, string>>>
     */
    public static function sheets(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $disposition = (string) $response->headers->get('content-disposition');
        preg_match('/filename="?([^";]+)"?/', $disposition, $match);
        $extension = pathinfo($match[1] ?? 'x.xlsx', PATHINFO_EXTENSION);
        $path = sys_get_temp_dir().'/descarga-'.bin2hex(random_bytes(6)).'.'.$extension;
        file_put_contents($path, $content);

        try {
            return match ($extension) {
                'csv' => [pathinfo($match[1], PATHINFO_FILENAME) => self::rows($path)],
                'zip' => self::zip($path),
                default => self::xlsx($path),
            };
        } finally {
            @unlink($path);
        }
    }

    /** @return list<array<string, string>> */
    public static function rows(string $path): array
    {
        $rows = array_map(fn (array $row) => $row[1], iterator_to_array(Spreadsheet::rows($path), false));

        return self::keyed($rows);
    }

    /** @return array<string, list<array<string, string>>> */
    private static function xlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(Spreadsheet::cellToString(...), $row->getCells());
                if (implode('', $cells) !== '') {
                    $rows[] = $cells;
                }
            }
            $sheets[$sheet->getName()] = self::keyed($rows);
        }
        $reader->close();

        return $sheets;
    }

    /** @return array<string, list<array<string, string>>> */
    private static function zip(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $csv = sys_get_temp_dir().'/'.bin2hex(random_bytes(6)).'.csv';
            file_put_contents($csv, $zip->getFromIndex($i));
            $sheets[pathinfo($name, PATHINFO_FILENAME)] = self::rows($csv);
            @unlink($csv);
        }
        $zip->close();

        return $sheets;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<array<string, string>>
     */
    private static function keyed(array $rows): array
    {
        $headers = array_shift($rows) ?? [];

        return array_map(fn (array $row) => array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), '')), $rows);
    }
}

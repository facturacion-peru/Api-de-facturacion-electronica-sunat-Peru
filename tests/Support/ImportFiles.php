<?php

namespace Tests\Support;

use App\DataTransfer\Spreadsheet;
use Illuminate\Http\UploadedFile;

/** Archivos de importación para las pruebas de la spec 014. */
final class ImportFiles
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows  todas como texto, como las escribe un usuario
     */
    public static function make(array $headers, array $rows, string $format = 'xlsx', string $name = 'productos'): UploadedFile
    {
        $sheet = new Spreadsheet($format);
        $sheet->sheet($name, array_fill_keys($headers, Spreadsheet::TEXT));
        foreach ($rows as $row) {
            $sheet->row($row);
        }
        $file = $sheet->finish($name);
        $path = sys_get_temp_dir().'/import-'.bin2hex(random_bytes(6)).'.'.pathinfo($file->filename, PATHINFO_EXTENSION);
        copy($file->path, $path);
        $file->delete();

        return new UploadedFile($path, "{$name}.{$format}", null, null, true);
    }

    /** Archivo con el contenido exacto dado (CSV a mano, binarios dañados…). */
    public static function raw(string $content, string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/import-'.bin2hex(random_bytes(6)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}

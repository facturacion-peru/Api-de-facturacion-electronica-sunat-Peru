<?php

namespace App\DataTransfer\Imports;

use App\DataTransfer\CustomerColumns;
use App\DataTransfer\ExportFile;
use App\DataTransfer\ProductColumns;
use App\DataTransfer\Spreadsheet;
use App\Enums\UnitOfMeasure;

/**
 * Plantillas de importación (spec 014, HU-4.1): la primera hoja es la que se
 * importa (vacía, con códigos y documentos como texto para que Excel no
 * pierda los ceros a la izquierda); la segunda explica cada columna.
 */
class Templates
{
    /** Filas vacías con formato de texto (el límite de una importación). */
    private const BLANK_ROWS = 2000;

    private const INSTRUCTIONS = ['columna' => Spreadsheet::TEXT, 'obligatoria' => Spreadsheet::TEXT, 'valores' => Spreadsheet::TEXT, 'ejemplo' => Spreadsheet::TEXT];

    public function build(string $kind): ExportFile
    {
        $sheet = new Spreadsheet(Spreadsheet::XLSX);

        if ($kind === 'products') {
            $sheet->sheet('productos', [...ProductColumns::CATALOG, ...ProductColumns::INITIAL_STOCK], ProductColumns::TEXT_IN_TEMPLATE, self::BLANK_ROWS);
            $rows = $this->productInstructions();
            $name = 'plantilla-productos';
        } else {
            $sheet->sheet('clientes', CustomerColumns::ALL, CustomerColumns::TEXT_IN_TEMPLATE, self::BLANK_ROWS);
            $rows = $this->customerInstructions();
            $name = 'plantilla-clientes';
        }

        $sheet->sheet('instrucciones', self::INSTRUCTIONS);
        foreach ($rows as $row) {
            $sheet->row($row);
        }

        return $sheet->finish($name);
    }

    /** @return list<list<string>> */
    private function productInstructions(): array
    {
        $units = collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $u) => "{$u->value} ({$u->label()})")->implode(', ');

        return [
            ['codigo', 'Sí', 'Texto de hasta 32 caracteres, único en tu empresa. Identifica al producto al actualizar.', 'P-001'],
            ['nombre', 'Sí', 'Hasta 255 caracteres.', 'Arroz extra 1 kg'],
            ['tipo', 'Sí', 'bien o servicio.', 'bien'],
            ['unidad', 'Sí', "Código SUNAT o nombre: {$units}.", 'NIU'],
            ['precio_venta', 'Sí', 'Precio con IGV, hasta 2 decimales (punto o coma).', '4.50'],
            ['afectacion_igv', 'Sí', '10 (gravado), 20 (exonerado) o 30 (inafecto).', '10'],
            ['stock_minimo', 'No', 'Hasta 3 decimales. Vacío en los servicios.', '5'],
            ['controla_vencimiento', 'No', 'si o no. Por defecto, no.', 'si'],
            ['activo', 'No', 'si o no. Por defecto, si.', 'si'],
            ['stock_inicial', 'No', 'Solo para productos nuevos que son bienes. Mayor que 0, hasta 3 decimales. En productos que ya existen se ignora: el stock se cambia con entradas y ajustes.', '20'],
            ['costo_unitario', 'No', 'Costo de cada unidad del stock inicial, hasta 4 decimales.', '3.20'],
            ['lote', 'No', 'Número de lote del stock inicial. Si se deja vacío, se numera solo.', 'L-0001'],
            ['vencimiento', 'Si controla vencimiento y hay stock inicial', 'Fecha AAAA-MM-DD, no pasada.', '2027-03-31'],
            ['', '', 'Se importa solo la hoja «productos», hasta 2 000 filas. El encabezado es la fila 1.', ''],
        ];
    }

    /** @return list<list<string>> */
    private function customerInstructions(): array
    {
        return [
            ['tipo_documento', 'Sí', 'DNI, CE (carné de extranjería) o RUC; también el código SUNAT 1, 4 o 6.', 'DNI'],
            ['numero_documento', 'Sí', 'DNI de 8 dígitos, CE de 8 a 12 letras o números, RUC de 11 dígitos válido. Identifica al cliente al actualizar.', '01234567'],
            ['nombre', 'Sí', 'Nombre o razón social, hasta 200 caracteres.', 'Ana Pérez'],
            ['direccion', 'No', 'Hasta 255 caracteres.', 'Av. Arequipa 123, Lima'],
            ['', '', 'Se importa solo la hoja «clientes», hasta 2 000 filas. El encabezado es la fila 1.', ''],
        ];
    }
}

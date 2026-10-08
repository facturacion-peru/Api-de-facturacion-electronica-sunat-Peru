<?php

namespace App\DataTransfer;

use App\Models\Product;

/**
 * Columnas de productos, comunes a la plantilla, la exportación y la
 * importación (spec 014): exportar y reimportar no produce cambios (CE-004).
 */
final class ProductColumns
{
    /** Columnas del catálogo (importables). */
    public const CATALOG = [
        'codigo' => Spreadsheet::TEXT,
        'nombre' => Spreadsheet::TEXT,
        'tipo' => Spreadsheet::TEXT,
        'unidad' => Spreadsheet::TEXT,
        'precio_venta' => Spreadsheet::MONEY,
        'afectacion_igv' => Spreadsheet::TEXT,
        'stock_minimo' => Spreadsheet::QUANTITY,
        'controla_vencimiento' => Spreadsheet::TEXT,
        'activo' => Spreadsheet::TEXT,
    ];

    /** Stock inicial, solo para productos nuevos (A-72). */
    public const INITIAL_STOCK = [
        'stock_inicial' => Spreadsheet::QUANTITY,
        'costo_unitario' => Spreadsheet::COST,
        'lote' => Spreadsheet::TEXT,
        'vencimiento' => Spreadsheet::TEXT,
    ];

    /** Solo en la exportación; la importación las ignora con aviso. */
    public const EXPORT_ONLY = [
        'stock_actual' => Spreadsheet::QUANTITY,
        'costo_stock' => Spreadsheet::MONEY,
    ];

    public const LOTS = [
        'codigo' => Spreadsheet::TEXT,
        'nombre' => Spreadsheet::TEXT,
        'lote' => Spreadsheet::TEXT,
        'ingreso' => Spreadsheet::TEXT,
        'vencimiento' => Spreadsheet::TEXT,
        'cantidad_restante' => Spreadsheet::QUANTITY,
        'costo_unitario' => Spreadsheet::COST,
    ];

    /** Columnas que Excel podría convertir en número: van como texto en la plantilla. */
    public const TEXT_IN_TEMPLATE = ['codigo', 'lote'];

    public const REQUIRED = ['codigo', 'nombre', 'tipo', 'unidad', 'precio_venta', 'afectacion_igv'];

    public const TYPES = ['good' => 'bien', 'service' => 'servicio'];

    /**
     * Valores del catálogo de un producto, en el orden de CATALOG.
     *
     * @return list<string|null>
     */
    public static function catalogValues(Product $product): array
    {
        return [
            $product->code,
            $product->name,
            self::TYPES[$product->type->value],
            $product->unit->value,
            $product->sale_price,
            $product->igv_affectation->value,
            $product->min_stock,
            self::yesNo($product->tracks_expiry),
            self::yesNo($product->active),
        ];
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'si' : 'no';
    }
}

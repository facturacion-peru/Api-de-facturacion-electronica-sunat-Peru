<?php

namespace App\DataTransfer\Exports;

use App\DataTransfer\ExportFile;
use App\DataTransfer\ProductColumns;
use App\DataTransfer\Spreadsheet;
use App\Models\Product;
use App\Models\ProductLot;
use App\Support\Decimal;

/**
 * Catálogo con stock y, opcionalmente, sus lotes con saldo (spec 014, HU-1).
 * Recorre los productos por bloques para no cargar todo en memoria.
 */
class ProductsExport
{
    private const CHUNK = 200;

    /** @param  array{status: string, type: ?string, search: ?string, lots: bool}  $filters */
    public function build(string $format, array $filters): ExportFile
    {
        $query = fn () => Product::query()
            ->withStock()
            ->listFilter($filters['status'], $filters['type'], $filters['search']);

        $sheet = new Spreadsheet($format);
        $sheet->sheet('productos', [...ProductColumns::CATALOG, ...ProductColumns::EXPORT_ONLY]);

        $query()->with(['lots' => fn ($lots) => $lots->where('remaining_quantity', '>', 0)])
            ->orderBy('name')->orderBy('id')
            ->chunk(self::CHUNK, function ($products) use ($sheet) {
                foreach ($products as $product) {
                    $tracksStock = $product->type->tracksStock();
                    $sheet->row([
                        ...ProductColumns::catalogValues($product),
                        $tracksStock ? $product->stock() : null,
                        $tracksStock ? $this->stockCost($product->lots) : null,
                    ]);
                }
            });

        if ($filters['lots']) {
            $sheet->sheet('lotes', ProductColumns::LOTS);
            $query()->with(['lots' => fn ($lots) => $lots->where('remaining_quantity', '>', 0)->orderBy('received_at')->orderBy('id')])
                ->orderBy('name')->orderBy('id')
                ->chunk(self::CHUNK, function ($products) use ($sheet) {
                    foreach ($products as $product) {
                        foreach ($product->lots as $lot) {
                            $sheet->row([
                                $product->code,
                                $product->name,
                                $lot->lot_number,
                                $lot->received_at?->toDateString(),
                                $lot->expires_at?->toDateString(),
                                $lot->remaining_quantity,
                                $lot->unit_cost,
                            ]);
                        }
                    }
                });
        }

        return $sheet->finish('productos-'.today()->toDateString());
    }

    /**
     * Costo del stock: saldo × costo de cada lote con costo conocido.
     *
     * @param  iterable<ProductLot>  $lots
     */
    private function stockCost(iterable $lots): string
    {
        $total = '0';
        foreach ($lots as $lot) {
            if ($lot->unit_cost !== null) {
                $total = bcadd($total, bcmul((string) $lot->remaining_quantity, (string) $lot->unit_cost, 8), 8);
            }
        }

        return Decimal::round($total, 2);
    }
}

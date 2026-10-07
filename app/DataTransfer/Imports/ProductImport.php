<?php

namespace App\DataTransfer\Imports;

use App\Audit\AuditLogger;
use App\DataTransfer\ProductColumns;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ProductService;
use App\Validation\ProductValidation;
use App\Validation\StockEntryValidation;
use Illuminate\Support\Facades\Validator;

/**
 * Importación de productos (spec 014, HU-4): cada fila se valida con las
 * reglas del alta y la edición manuales; el stock inicial de un producto
 * nuevo es una entrada de mercadería normal (A-72).
 */
class ProductImport implements RowImport
{
    /** Campo del modelo o de la entrada => columna del archivo. */
    private const FIELDS = [
        'code' => 'codigo', 'name' => 'nombre', 'type' => 'tipo', 'unit' => 'unidad', 'sale_price' => 'precio_venta',
        'igv_affectation' => 'afectacion_igv', 'min_stock' => 'stock_minimo', 'tracks_expiry' => 'controla_vencimiento', 'active' => 'activo',
        'quantity' => 'stock_inicial', 'unit_cost' => 'costo_unitario', 'lot_number' => 'lote', 'expires_at' => 'vencimiento',
    ];

    private const STOCK_COLUMNS = ['stock_inicial', 'costo_unitario', 'lote', 'vencimiento'];

    public function __construct(
        private ProductService $products,
        private InventoryService $inventory,
    ) {}

    public function requiredColumns(): array
    {
        return ProductColumns::REQUIRED;
    }

    public function knownColumns(): array
    {
        return [...array_keys(ProductColumns::CATALOG), ...array_keys(ProductColumns::INITIAL_STOCK)];
    }

    public function readOnlyColumns(): array
    {
        return array_keys(ProductColumns::EXPORT_ONLY);
    }

    public function analyze(array $records, array $columns, string $mode): Analysis
    {
        $analysis = new Analysis;
        $duplicates = $this->duplicates($records);
        $existing = Product::query()
            ->whereIn('code', array_filter(array_map(fn ($record) => trim($record[1]['codigo'] ?? ''), $records)))
            ->get()->keyBy('code');

        foreach ($records as [$row, $values]) {
            $code = trim($values['codigo'] ?? '');
            if (isset($duplicates[$code])) {
                $analysis->error($row, 'codigo', "El código {$code} se repite en las filas ".$this->listRows($duplicates[$code]).'.');

                continue;
            }

            $product = $mode === 'upsert' ? $existing->get($code) : null;
            $data = $this->catalogData($values, $columns, $product === null);
            $validator = Validator::make(
                $data,
                [...ProductValidation::rules($product ? ['sometimes', 'required'] : ['required'], $data, $product), 'active' => ['sometimes', 'boolean']],
                ProductValidation::messages(),
                self::FIELDS,
            );
            $failed = $this->collect($analysis, $row, $validator->errors()->messages());

            $hasStock = collect(self::STOCK_COLUMNS)->contains(fn ($column) => trim($values[$column] ?? '') !== '');
            $entry = null;
            if ($hasStock && $product !== null) {
                $analysis->warning($row, 'stock_inicial', 'El producto ya existe: el stock no se cambia al importar (usa entradas o ajustes).');
            } elseif ($hasStock) {
                $entry = $this->entryData($values);
                $failed = $this->validateEntry($analysis, $row, $data, $entry) || $failed;
            }

            if ($failed) {
                continue;
            }

            if ($product === null) {
                $analysis->rows[] = ['row' => $row, 'action' => 'create', 'data' => $data, 'entry' => $entry];
                $analysis->changes[] = ['row' => $row, 'action' => 'create', 'key' => $data['code'], 'name' => $data['name'], 'stock' => $entry['quantity'] ?? null];

                continue;
            }

            $fields = $this->diff($product, $data);
            if ($fields === []) {
                $analysis->unchanged++;

                continue;
            }

            $analysis->rows[] = ['row' => $row, 'action' => 'update', 'id' => $product->id, 'updated_at' => $product->updated_at?->toIso8601String(), 'data' => $data];
            $analysis->changes[] = ['row' => $row, 'action' => 'update', 'key' => $product->code, 'name' => $product->name, 'fields' => $fields];
        }

        return $analysis;
    }

    public function apply(array $row, User $actor, array &$result): void
    {
        $data = $row['data'];

        if ($row['action'] === 'update') {
            $product = Product::query()->whereKey($row['id'])->lockForUpdate()->first();
            if ($product === null || $product->updated_at?->toIso8601String() !== $row['updated_at']) {
                throw new ImportConflict("El producto de la fila {$row['row']} cambió desde la vista previa.");
            }
            $this->revalidate($data, $product, $row['row']);
            $this->products->update($product, $data, $actor);
            $result['updated']++;

            return;
        }

        $this->revalidate($data, null, $row['row']);
        $product = $this->products->create($data, $actor);
        $result['created']++;

        if ($row['entry'] !== null) {
            $this->inventory->registerEntry($product, array_filter($row['entry'], fn ($value) => $value !== null), $actor);
            $result['entries']++;
        }
    }

    /**
     * Datos del catálogo de una fila. Las columnas que no están en el archivo
     * no se tocan al actualizar; al crear toman su valor por defecto.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function catalogData(array $values, array $columns, bool $creating): array
    {
        $has = fn (string $column) => in_array($column, $columns, true);
        $data = [
            'code' => Cells::text($values['codigo'] ?? ''),
            'name' => Cells::text($values['nombre'] ?? ''),
            'type' => $this->type($values['tipo'] ?? ''),
            'unit' => $this->unit($values['unidad'] ?? ''),
            'sale_price' => Cells::decimal($values['precio_venta'] ?? ''),
            'igv_affectation' => Cells::text($values['afectacion_igv'] ?? ''),
        ];

        if ($has('stock_minimo')) {
            $data['min_stock'] = Cells::decimal($values['stock_minimo']);
        }
        // Al actualizar, un sí/no vacío deja el valor actual.
        foreach (['controla_vencimiento' => ['tracks_expiry', false], 'activo' => ['active', true]] as $column => [$field, $default]) {
            $value = $has($column) ? $values[$column] : '';
            if ($creating || trim($value) !== '') {
                $data[$field] = Cells::boolean($value, $default);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{quantity: ?string, unit_cost: ?string, lot_number: ?string, expires_at: ?string}
     */
    private function entryData(array $values): array
    {
        return [
            'quantity' => Cells::decimal($values['stock_inicial'] ?? ''),
            'unit_cost' => Cells::decimal($values['costo_unitario'] ?? ''),
            'lot_number' => Cells::text($values['lote'] ?? ''),
            'expires_at' => Cells::date($values['vencimiento'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, ?string>  $entry
     */
    private function validateEntry(Analysis $analysis, int $row, array $data, array $entry): bool
    {
        if (ProductType::tryFrom((string) $data['type']) === ProductType::Service) {
            $analysis->error($row, 'stock_inicial', 'Los servicios no tienen stock.');

            return true;
        }

        $tracksExpiry = ($data['tracks_expiry'] ?? false) === true;
        $validator = Validator::make(
            array_filter($entry, fn ($value) => $value !== null),
            StockEntryValidation::rules(null, UnitOfMeasure::tryFrom((string) $data['unit']), $tracksExpiry),
            [...StockEntryValidation::messages(), 'quantity.required' => 'Indica el stock inicial.'],
            self::FIELDS,
        );

        return $this->collect($analysis, $row, $validator->errors()->messages());
    }

    /**
     * @param  array<string, list<string>>  $messages
     */
    private function collect(Analysis $analysis, int $row, array $messages): bool
    {
        foreach ($messages as $field => $fieldMessages) {
            $analysis->error($row, self::FIELDS[$field] ?? $field, $fieldMessages[0]);
        }

        return $messages !== [];
    }

    /**
     * Cambios campo por campo (con los nombres de columna), sin guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(Product $product, array $data): array
    {
        $copy = clone $product;
        $copy->fill($data);
        $fields = [];
        foreach (AuditLogger::diff($copy) as $field => $change) {
            $fields[self::FIELDS[$field] ?? $field] = $change;
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $data */
    private function revalidate(array $data, ?Product $product, int $row): void
    {
        $rules = [...ProductValidation::rules($product ? ['sometimes', 'required'] : ['required'], $data, $product), 'active' => ['sometimes', 'boolean']];
        if (Validator::make($data, $rules)->fails()) {
            throw new ImportConflict("La fila {$row} ya no es válida: los productos cambiaron desde la vista previa.");
        }
    }

    /**
     * Códigos repetidos dentro del archivo => sus filas.
     *
     * @param  list<array{int, array<string, string>}>  $records
     * @return array<string, list<int>>
     */
    private function duplicates(array $records): array
    {
        $rows = [];
        foreach ($records as [$row, $values]) {
            $code = trim($values['codigo'] ?? '');
            if ($code !== '') {
                $rows[$code][] = $row;
            }
        }

        return array_filter($rows, fn (array $list) => count($list) > 1);
    }

    /** @param  list<int>  $rows */
    private function listRows(array $rows): string
    {
        $last = array_pop($rows);

        return $rows === [] ? (string) $last : implode(', ', $rows).' y '.$last;
    }

    private function type(string $value): ?string
    {
        $normalized = mb_strtolower(trim($value));
        $type = array_search($normalized, ProductColumns::TYPES, true);

        return match (true) {
            $normalized === '' => null,
            $type !== false => $type,
            default => $value,
        };
    }

    private function unit(string $value): ?string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        foreach (UnitOfMeasure::cases() as $unit) {
            if ($normalized === mb_strtolower($unit->value) || $normalized === mb_strtolower($unit->label())) {
                return $unit->value;
            }
        }

        return $value;
    }
}

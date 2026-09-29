<?php

namespace App\Models;

use App\Enums\IgvAffectation;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Support\Decimal;
use App\Tenancy\BelongsToCompany;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bien o servicio que vende la empresa (spec 002). */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'unit',
        'sale_price',
        'igv_affectation',
        'min_stock',
        'tracks_expiry',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'unit' => UnitOfMeasure::class,
            'igv_affectation' => IgvAffectation::class,
            'sale_price' => 'decimal:2',
            'min_stock' => 'decimal:3',
            'tracks_expiry' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Añade `stock_sum` y `available_sum` en la misma consulta, para que los
     * listados no hagan una consulta por producto.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithStock(Builder $query): void
    {
        $query->withSum('lots as stock_sum', 'remaining_quantity')
            ->withSum(['lots as available_sum' => fn ($lots) => $lots
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()))], 'remaining_quantity');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(ProductLot::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Stock físico: suma de los saldos de todos sus lotes. */
    public function stock(): string
    {
        return $this->formatQuantity(
            array_key_exists('stock_sum', $this->attributes) ? $this->attributes['stock_sum'] : $this->lots()->sum('remaining_quantity')
        );
    }

    /** Disponible para vender: excluye los lotes vencidos (HU-3.5). */
    public function availableStock(): string
    {
        if (array_key_exists('available_sum', $this->attributes)) {
            return $this->formatQuantity($this->attributes['available_sum']);
        }

        return $this->formatQuantity(
            $this->lots()
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', today()))
                ->sum('remaining_quantity')
        );
    }

    private function formatQuantity(mixed $value): string
    {
        return Decimal::fromDb($value);
    }
}

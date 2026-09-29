<?php

namespace App\Models;

use App\Enums\IgvAffectation;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Tenancy\BelongsToCompany;
use Database\Factories\ProductFactory;
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
        return $this->formatQuantity($this->lots()->sum('remaining_quantity'));
    }

    /** Disponible para vender: excluye los lotes vencidos (HU-3.5). */
    public function availableStock(): string
    {
        return $this->formatQuantity(
            $this->lots()
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', today()))
                ->sum('remaining_quantity')
        );
    }

    private function formatQuantity(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 3);
    }
}

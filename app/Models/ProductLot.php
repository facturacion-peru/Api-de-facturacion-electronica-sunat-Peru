<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Database\Factories\ProductLotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unidades de un producto que entraron juntas. `remaining_quantity` es una
 * caché de la suma de sus movimientos que solo escribe InventoryService.
 */
class ProductLot extends Model
{
    /** @use HasFactory<ProductLotFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'product_id',
        'lot_number',
        'received_at',
        'expires_at',
        'initial_quantity',
        'remaining_quantity',
        'unit_cost',
        'reference',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'expires_at' => 'date',
            'initial_quantity' => 'decimal:3',
            'remaining_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'lot_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

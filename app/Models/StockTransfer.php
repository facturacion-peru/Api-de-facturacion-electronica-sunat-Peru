<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Traslado de stock entre dos locales (spec 017, HU-3): origen de sus movimientos. */
class StockTransfer extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'product_id',
        'from_establishment_id',
        'to_establishment_id',
        'quantity',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'from_establishment_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'to_establishment_id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source');
    }
}

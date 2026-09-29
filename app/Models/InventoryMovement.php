<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Cambio de stock inmutable (RF-011). Se escribe solo con InventoryService;
 * los errores se corrigen con una reversión, nunca editando (RF-016).
 */
class InventoryMovement extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'product_id',
        'lot_id',
        'type',
        'quantity',
        'lot_balance_after',
        'product_balance_after',
        'reason',
        'note',
        'source_type',
        'source_id',
        'reverses_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'reason' => AdjustmentReason::class,
            'quantity' => 'decimal:3',
            'lot_balance_after' => 'decimal:3',
            'product_balance_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

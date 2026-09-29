<?php

namespace App\Models;

use App\Sales\Exceptions\SalesDocumentImmutable;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de comprobante tal como se emitió (RF-006); inmutable. */
class SalesDocumentLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'sales_document_id', 'product_id', 'position', 'product_code', 'product_name', 'unit',
        'igv_affectation', 'quantity', 'unit_price', 'unit_value', 'gross_amount', 'discount', 'base_amount', 'igv', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_value' => 'decimal:10',
            'gross_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'igv' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new SalesDocumentImmutable);
        static::deleting(fn () => throw new SalesDocumentImmutable);
    }

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

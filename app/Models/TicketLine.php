<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de ticket con la copia del producto al momento de vender (RF-009). */
class TicketLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'ticket_id',
        'product_id',
        'position',
        'product_code',
        'product_name',
        'unit',
        'igv_affectation',
        'quantity',
        'unit_price',
        'gross_amount',
        'discount',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

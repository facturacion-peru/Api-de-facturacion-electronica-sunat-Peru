<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;
use App\Tenancy\BelongsToCompany;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta interna no tributaria (spec 003). Nunca es comprobante de pago
 * (A-17): no genera XML ni va a SUNAT, y siempre lleva LEGAL_NOTICE.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use BelongsToCompany, HasFactory;

    public const LEGAL_NOTICE = 'Documento interno — no es comprobante de pago';

    protected $fillable = [
        'company_id',
        'number',
        'status',
        'seller_id',
        'customer_name',
        'customer_document',
        'payment_method',
        'subtotal',
        'discount_total',
        'total',
        'idempotency_key',
        'issued_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** T-000001: no puede confundirse con una serie SUNAT (4 caracteres). */
    protected function displayNumber(): Attribute
    {
        return Attribute::get(fn () => 'T-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TicketLine::class)->orderBy('position');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}

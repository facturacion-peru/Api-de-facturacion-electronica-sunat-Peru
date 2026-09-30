<?php

namespace App\Models;

use App\Enums\CorrectionStatus;
use App\Enums\CreditNoteReason;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\SalesDocumentStatus;
use App\Enums\SunatEnvironment;
use App\Sales\Exceptions\SalesDocumentImmutable;
use App\Tenancy\BelongsToCompany;
use Database\Factories\SalesDocumentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura o boleta electrónica (spec 005). Conserva los datos del momento de
 * emitir (RF-006) y no se edita (RF-014): solo cambian los campos del envío.
 */
class SalesDocument extends Model
{
    /** @use HasFactory<SalesDocumentFactory> */
    use BelongsToCompany, HasFactory;

    /** Lo único que cambia después de emitir: el estado del envío a SUNAT. */
    private const MUTABLE = [
        'status', 'sunat_code', 'sunat_message', 'sunat_notes', 'cdr',
        'attempts', 'next_attempt_at', 'locked_until', 'updated_at',
        // Spec 007: resultado de las notas y descarte de rechazados.
        'correction_status', 'discarded_at', 'discarded_by', 'discard_reason',
    ];

    protected $fillable = [
        'company_id', 'series_id', 'reference_document_id', 'note_reason_code', 'note_reason', 'restock',
        'correction_status', 'discarded_at', 'discarded_by', 'discard_reason', 'document_type', 'series_code', 'number', 'environment', 'issued_at',
        'seller_id', 'payment_method', 'currency',
        'issuer_ruc', 'issuer_name', 'issuer_trade_name', 'issuer_address', 'issuer_ubigeo', 'issuer_department', 'issuer_province', 'issuer_district',
        'customer_id', 'customer_document_type', 'customer_document_number', 'customer_name', 'customer_address',
        'op_gravadas', 'op_exoneradas', 'op_inafectas', 'igv', 'discount_total', 'total',
        'status', 'sunat_code', 'sunat_message', 'sunat_notes', 'xml', 'hash', 'cdr',
        'attempts', 'next_attempt_at', 'locked_until', 'idempotency_key',
    ];

    /** XML y CDR solo se entregan por sus descargas. */
    protected $hidden = ['xml', 'cdr'];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'environment' => SunatEnvironment::class,
            'payment_method' => PaymentMethod::class,
            'status' => SalesDocumentStatus::class,
            'note_reason_code' => CreditNoteReason::class,
            'restock' => 'boolean',
            'correction_status' => CorrectionStatus::class,
            'discarded_at' => 'datetime',
            'issued_at' => 'datetime',
            'op_gravadas' => 'decimal:2',
            'op_exoneradas' => 'decimal:2',
            'op_inafectas' => 'decimal:2',
            'igv' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'sunat_notes' => 'array',
            'next_attempt_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SalesDocument $document) {
            if (array_diff(array_keys($document->getDirty()), self::MUTABLE) !== []) {
                throw new SalesDocumentImmutable;
            }
        });
        static::deleting(fn () => throw new SalesDocumentImmutable);
    }

    /** B001-00000123. */
    protected function displayNumber(): Attribute
    {
        return Attribute::get(fn () => $this->series_code.'-'.str_pad((string) $this->number, 8, '0', STR_PAD_LEFT));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class)->orderBy('position');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SunatSubmission::class)->orderBy('started_at')->orderBy('id');
    }

    /** Nota de crédito → comprobante que modifica. */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_document_id');
    }

    /** Factura o boleta → sus notas de crédito. */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'reference_document_id')->orderBy('id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

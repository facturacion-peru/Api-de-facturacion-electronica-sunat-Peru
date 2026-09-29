<?php

namespace App\Models;

use App\Enums\SubmissionResult;
use App\Enums\SubmissionTrigger;
use App\Sales\Exceptions\SalesDocumentImmutable;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un intento de envío a SUNAT (RF-013). Inmutable. */
class SunatSubmission extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'sales_document_id', 'trigger', 'started_at', 'duration_ms', 'result', 'code', 'message', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => SubmissionTrigger::class,
            'result' => SubmissionResult::class,
            'started_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

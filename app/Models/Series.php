<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Tenancy\BelongsToCompany;
use Database\Factories\SeriesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Serie de comprobantes con su correlativo (HU-3). */
class Series extends Model
{
    /** @use HasFactory<SeriesFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'series';

    protected $fillable = [
        'company_id',
        'establishment_id',
        'document_type',
        'code',
        'last_number',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'last_number' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function nextNumberPreview(): int
    {
        return $this->last_number + 1;
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}

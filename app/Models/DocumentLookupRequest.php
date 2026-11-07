<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una llamada real al servicio de consulta (spec 018, A-96), sin el número consultado. */
class DocumentLookupRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'user_id',
        'document_type',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}

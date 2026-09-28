<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Local de la empresa registrado en SUNAT (código de anexo). */
class Establishment extends Model
{
    /** @use HasFactory<EstablishmentFactory> */
    use BelongsToCompany, HasFactory;

    public const MAIN_CODE = '0000';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'address',
        'ubigeo',
        'is_main',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(UbiDistrito::class, 'ubigeo');
    }
}

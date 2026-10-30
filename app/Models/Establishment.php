<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(UbiDistrito::class, 'ubigeo');
    }

    /** Vendedores asignados (A-87); el administrador de empresa trabaja en todos sin filas. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('company_id')->withTimestamps();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}

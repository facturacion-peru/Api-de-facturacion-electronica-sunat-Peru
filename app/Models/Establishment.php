<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class);
    }

    /**
     * El local a nombrar en lo impreso (spec 017): el principal no se nombra,
     * para que una empresa con un solo local vea sus papeles como siempre.
     */
    public static function branchOf(?self $establishment): ?self
    {
        return $establishment !== null && ! $establishment->is_main ? $establishment : null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}

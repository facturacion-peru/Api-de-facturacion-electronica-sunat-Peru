<?php

namespace App\Models;

use App\Enums\PersonType;
use App\Enums\TaxRegime;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Empresa cliente del SaaS y unidad de aislamiento. No es un modelo con scope
 * de empresa: el usuario de empresa accede a la suya vía /company.
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'ruc',
        'razon_social',
        'nombre_comercial',
        'person_type',
        'tax_regime',
        'email',
        'phone',
        'logo_path',
        'expiry_warning_days',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'person_type' => PersonType::class,
            'tax_regime' => TaxRegime::class,
            'active' => 'boolean',
            'expiry_warning_days' => 'integer',
        ];
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class)->withoutGlobalScopes();
    }

    /** El domicilio fiscal vive en el establecimiento principal. */
    public function mainEstablishment(): HasOne
    {
        return $this->hasOne(Establishment::class)->withoutGlobalScopes()->where('is_main', true);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class)->withoutGlobalScopes();
    }
}

<?php

namespace App\Models;

use App\Traits\ConfigurableCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, ConfigurableCompany;

    protected $fillable = [
        'ruc',
        'razon_social',
        'nombre_comercial',
        'direccion',
        'ubigeo',
        'distrito',
        'provincia',
        'departamento',
        'telefono',
        'email',
        'web',
        'usuario_sol',
        'clave_sol',
        'certificado_pem',
        'certificado_password',
        'endpoint_beta',
        'endpoint_produccion',
        'modo_produccion',
        'logo_path',
        'configuraciones',
        'activo',
    ];

    protected $casts = [
        'configuraciones' => 'array',
        'modo_produccion' => 'boolean',
        'activo' => 'boolean',
    ];

    protected $hidden = [
        'clave_sol',
        'certificado_pem',
        'certificado_password',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function boletas(): HasMany
    {
        return $this->hasMany(Boleta::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class);
    }

    public function dispatchGuides(): HasMany
    {
        return $this->hasMany(DispatchGuide::class);
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }

    public function voidedDocuments(): HasMany
    {
        return $this->hasMany(VoidedDocument::class);
    }

    public function getEndpointAttribute(): string
    {
        return $this->modo_produccion ? $this->endpoint_produccion : $this->endpoint_beta;
    }

    public function scopeActive($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Bootstrap del modelo
     */
    protected static function boot()
    {
        parent::boot();
        
        // Al crear una nueva empresa, inicializar con configuraciones por defecto
        static::creating(function ($company) {
            if (empty($company->configuraciones)) {
                $company->configuraciones = $company->getDefaultConfigurations();
            }
            
            // Asignar endpoints por defecto si no están definidos
            if (empty($company->endpoint_beta)) {
                $defaults = $company->getDefaultConfigurations();
                $company->endpoint_beta = $defaults['servicios_sunat']['facturacion']['beta']['endpoint'];
            }
            
            if (empty($company->endpoint_produccion)) {
                $defaults = $company->getDefaultConfigurations();
                $company->endpoint_produccion = $defaults['servicios_sunat']['facturacion']['produccion']['endpoint'];
            }
        });
        
        // Al recuperar una empresa, asegurar que tenga todas las configuraciones
        static::retrieved(function ($company) {
            if (empty($company->configuraciones)) {
                $company->mergeWithDefaults();
            }
        });
    }

    /**
     * Método para migrar configuraciones existentes
     * Útil para empresas creadas antes de implementar este sistema
     */
    public function migrateToNewConfigStructure(): bool
    {
        $this->mergeWithDefaults();
        
        // Migrar endpoints existentes a la nueva estructura
        if (!empty($this->endpoint_beta) || !empty($this->endpoint_produccion)) {
            $configs = $this->configuraciones;
            
            if (!empty($this->endpoint_beta)) {
                $configs['servicios_sunat']['facturacion']['beta']['endpoint'] = $this->endpoint_beta;
            }
            
            if (!empty($this->endpoint_produccion)) {
                $configs['servicios_sunat']['facturacion']['produccion']['endpoint'] = $this->endpoint_produccion;
            }
            
            $this->configuraciones = $configs;
        }
        
        return $this->save();
    }
}
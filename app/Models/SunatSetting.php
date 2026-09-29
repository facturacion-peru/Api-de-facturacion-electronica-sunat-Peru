<?php

namespace App\Models;

use App\Enums\SunatEnvironment;
use App\Enums\SunatStatus;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración SUNAT de una empresa. La clave SOL se guarda cifrada y
 * nunca sale en respuestas (RF-001/002).
 */
class SunatSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'environment',
        'status',
        'sol_user',
        'sol_password',
        'sol_verified_at',
        'last_validated_at',
        'last_validation_error',
        'validated_by',
    ];

    protected $hidden = ['sol_password'];

    protected function casts(): array
    {
        return [
            'environment' => SunatEnvironment::class,
            'status' => SunatStatus::class,
            'sol_password' => 'encrypted',
            'sol_verified_at' => 'datetime',
            'last_validated_at' => 'datetime',
        ];
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}

<?php

namespace App\Models;

use App\Audit\Exceptions\AuditLogImmutable;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Registro de auditoría: inmutable y escrito solo por App\Audit\AuditLogger.
 * Admite company_id nulo para eventos de plataforma (p. ej. un login fallido
 * de un correo que no existe).
 */
class AuditLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'actor_id',
        'action',
        'auditable_type',
        'auditable_id',
        'changes',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AuditLogImmutable);
        static::deleting(fn () => throw new AuditLogImmutable);
    }

    public static function allowsNullCompany(): bool
    {
        return true;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}

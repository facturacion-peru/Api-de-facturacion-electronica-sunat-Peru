<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Tenancy\BelongsToCompany;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Certificado digital de la empresa. PEM (con la clave privada) y contraseña
 * cifrados; solo los metadatos se exponen (RF-001/002).
 */
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'pem',
        'password',
        'subject',
        'ruc',
        'serial_number',
        'valid_from',
        'valid_to',
        'status',
        'uploaded_by',
        'replaced_at',
    ];

    protected $hidden = ['pem', 'password'];

    protected function casts(): array
    {
        return [
            'pem' => 'encrypted',
            'password' => 'encrypted',
            'status' => CertificateStatus::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'replaced_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->valid_to->isPast();
    }

    public function daysToExpire(): int
    {
        return (int) now()->diffInDays($this->valid_to, false);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

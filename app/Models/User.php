<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Persona que inicia sesión. Es administrador de la plataforma (sin empresa)
 * o pertenece a exactamente una empresa con un rol (A-04, RF-011).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** Los correos se comparan sin distinguir mayúsculas ni espacios. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeEmail($value));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Pertenencia a su empresa. Se lee sin scope de empresa porque es
     * justamente lo que determina el contexto de empresa.
     */
    public function membership(): HasOne
    {
        return $this->hasOne(CompanyMembership::class)->withoutGlobalScopes();
    }

    /** El enlace apunta a la pantalla del frontend, no a una ruta de Laravel. */
    /** Locales asignados a un vendedor (A-87); para decidir el acceso, usar EstablishmentAccess. */
    public function establishments(): BelongsToMany
    {
        return $this->belongsToMany(Establishment::class)->withPivot('company_id')->withTimestamps();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin;
    }

    public function hasCompanyRole(CompanyRole $role): bool
    {
        return $this->membership?->role === $role;
    }
}

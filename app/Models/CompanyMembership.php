<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Tenancy\BelongsToCompany;
use Database\Factories\CompanyMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pertenencia de un usuario a una empresa, con su rol. */
class CompanyMembership extends Model
{
    /** @use HasFactory<CompanyMembershipFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'company_user';

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

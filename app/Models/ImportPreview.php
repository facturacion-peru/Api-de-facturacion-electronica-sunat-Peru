<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Vista previa de una importación (spec 014): pertenece a un usuario y a una
 * empresa, no guarda nada del catálogo hasta confirmarse y caduca.
 *
 * @property array<int, array<string, mixed>> $rows
 * @property array<string, int> $summary
 * @property array<int, array<string, mixed>> $errors
 * @property array<int, array<string, mixed>> $warnings
 * @property array<int, array<string, mixed>> $changes
 */
class ImportPreview extends Model
{
    use BelongsToCompany, HasUuids, MassPrunable;

    public const TTL_MINUTES = 30;

    protected $fillable = [
        'company_id', 'user_id', 'kind', 'mode', 'rows', 'summary', 'errors', 'warnings', 'changes', 'expires_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'summary' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
            'changes' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canConfirm(): bool
    {
        return $this->errors === [] && $this->confirmed_at === null && ! $this->isExpired();
    }

    /** Se borran un día después de caducar (el 410 informa mientras tanto). Sin contexto de empresa: corre en el scheduler. */
    public function prunable(): Builder
    {
        return static::withoutTenancy()->where('expires_at', '<', now()->subDay());
    }
}

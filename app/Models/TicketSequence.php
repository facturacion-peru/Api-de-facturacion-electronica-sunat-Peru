<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Último número de ticket de una empresa; se bloquea al numerar (RF-002). */
class TicketSequence extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'company_id';

    protected $fillable = ['company_id', 'last_number'];

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}

<?php

namespace App\Models;

use App\Enums\CustomerDocumentType;
use App\Tenancy\BelongsToCompany;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comprador identificado de la empresa (spec 005, RF-030). No se borra (A-34). */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'document_type',
        'document_number',
        'name',
        'address',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => CustomerDocumentType::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

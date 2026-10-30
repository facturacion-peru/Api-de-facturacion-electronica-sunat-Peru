<?php

namespace App\Models;

use App\Enums\CustomerDocumentType;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que devolvió el servicio de consulta para un DNI o RUC (spec 018). Es de
 * la plataforma, sin empresa: datos públicos reutilizables por todas (A-93 ⚖️).
 * Solo lo escribe DocumentLookupService y ninguna ruta lo lista.
 */
class DocumentLookup extends Model
{
    protected $fillable = [
        'document_type',
        'document_number',
        'name',
        'address',
        'ubigeo',
        'ruc_status',
        'ruc_condition',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => CustomerDocumentType::class,
            'fetched_at' => 'datetime',
        ];
    }
}

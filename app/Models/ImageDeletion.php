<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Borrado pendiente de un objeto del disco de imágenes (spec 016). No tiene
 * empresa: guarda solo la ruta del objeto, un UUID sin datos.
 */
class ImageDeletion extends Model
{
    /** A partir de aquí `images:cleanup` deja de intentarlo y avisa. */
    public const MAX_ATTEMPTS = 10;

    protected $fillable = ['path', 'attempts', 'last_error'];

    protected function casts(): array
    {
        return ['attempts' => 'integer'];
    }

    /** Agrega los objetos a la cola sin duplicar los que ya estaban. */
    public static function enqueue(string ...$paths): void
    {
        foreach ($paths as $path) {
            static::query()->firstOrCreate(['path' => $path]);
        }
    }
}

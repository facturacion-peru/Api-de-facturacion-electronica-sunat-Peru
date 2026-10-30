<?php

namespace App\Console\Commands;

use App\Images\ImageStore;
use App\Models\ImageDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reintenta los borrados del disco de imágenes que fallaron (spec 016, A-82).
 * Corre a diario; a los 10 intentos deja de insistir y avisa en el log, para
 * revisarlo a mano. Así no hace falta listar el bucket.
 */
class CleanupImages extends Command
{
    protected $signature = 'images:cleanup {--limit=500 : Máximo de objetos por ejecución}';

    protected $description = 'Reintenta borrar las imágenes que no se pudieron borrar';

    public function handle(ImageStore $store): int
    {
        $pending = ImageDeletion::query()
            ->where('attempts', '<', ImageDeletion::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $deleted = 0;
        foreach ($pending as $deletion) {
            $error = $store->tryDelete($deletion->path);

            if ($error === null) {
                $deletion->delete();
                $deleted++;

                continue;
            }

            $deletion->update(['attempts' => $deletion->attempts + 1, 'last_error' => mb_substr($error, 0, 2000)]);

            if ($deletion->attempts >= ImageDeletion::MAX_ATTEMPTS) {
                Log::error('Se dejó de intentar borrar una imagen: revisarla a mano.', ['path' => $deletion->path, 'error' => $error]);
            }
        }

        $this->info("Imágenes borradas: {$deleted} de {$pending->count()}.");

        return self::SUCCESS;
    }
}

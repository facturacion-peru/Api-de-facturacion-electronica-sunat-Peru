<?php

namespace App\Console\Commands;

use App\Images\CompanyLogo;
use App\Images\ImageStore;
use App\Images\LogoProcessor;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lleva los logos antiguos (`companies/{id}/…` en el disco `public`) al disco
 * de imágenes como PNG con alfa (spec 016, HU-4). Lo corre cada despliegue:
 * los ya movidos no tienen «/» y no se tocan. Si uno falla, sigue con los
 * demás y el logo antiguo se sigue viendo desde `public`.
 */
class MoveLogos extends Command
{
    protected $signature = 'images:move-logos';

    protected $description = 'Mueve los logos antiguos del disco public al disco de imágenes';

    public function handle(LogoProcessor $logos, ImageStore $images): int
    {
        $companies = Company::query()->where('logo_path', 'like', '%/%')->orderBy('id')->get();
        $public = Storage::disk('public');
        $moved = 0;

        foreach ($companies as $company) {
            $old = $company->logo_path;

            if (! CompanyLogo::isLegacy($old)) {
                continue;
            }

            if (! $public->exists($old)) {
                // Ya no hay nada que mover: la ruta rota solo daría un logo roto.
                $company->update(['logo_path' => null]);
                $this->warn("Empresa {$company->id}: el logo {$old} no existe; se quitó la ruta.");
                $moved++;

                continue;
            }

            try {
                $path = ImageStore::logoPath((string) Str::uuid());
                $images->put([$path => $logos->process((string) $public->get($old))], 'image/png');
                $company->update(['logo_path' => $path]);
                $public->delete($old);
                $moved++;
            } catch (Throwable $e) {
                Log::warning('No se pudo mover un logo al disco de imágenes.', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                $this->error("Empresa {$company->id}: no se pudo mover el logo ({$e->getMessage()}).");
            }
        }

        $this->info("Logos movidos: {$moved} de {$companies->count()}.");

        return $moved === $companies->count() ? self::SUCCESS : self::FAILURE;
    }
}

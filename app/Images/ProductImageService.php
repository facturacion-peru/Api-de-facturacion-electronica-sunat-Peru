<?php

namespace App\Images;

use App\Audit\AuditLogger;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Foto del producto (spec 016, HU-1). Los objetos nuevos se suben fuera de la
 * transacción; la fila se bloquea para ordenar subidas simultáneas, y los
 * anteriores se borran después del commit. Así nunca se ve una foto rota.
 */
class ProductImageService
{
    public function __construct(
        private ImageProcessor $processor,
        private ImageStore $store,
        private AuditLogger $audit,
    ) {}

    public function replace(Product $product, UploadedFile $file, User $actor): Product
    {
        try {
            $image = $this->processor->process($file->getContent());
        } catch (InvalidImage) {
            throw ValidationException::withMessages(['image' => 'La foto no se puede leer. Prueba con otra.']);
        }

        $key = (string) Str::uuid();
        [$large, $thumb] = ImageStore::productPaths($key);
        $this->store->put([$large => $image->large, $thumb => $image->thumb], 'image/webp');

        try {
            $previous = $this->swap($product, $key, $actor);
        } catch (Throwable $e) {
            $this->store->delete($large, $thumb);

            throw $e;
        }

        if ($previous !== null) {
            $this->store->delete(...ImageStore::productPaths($previous));
        }

        return $product->refresh();
    }

    /** Idempotente: sin foto no hay nada que borrar ni registrar. */
    public function remove(Product $product, User $actor): Product
    {
        $previous = $this->swap($product, null, $actor);

        if ($previous !== null) {
            $this->store->delete(...ImageStore::productPaths($previous));
        }

        return $product->refresh();
    }

    /** Cambia la clave con la fila bloqueada y devuelve la anterior. */
    private function swap(Product $product, ?string $key, User $actor): ?string
    {
        return DB::transaction(function () use ($product, $key, $actor) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->image_key;

            if ($previous === null && $key === null) {
                return null;
            }

            $locked->forceFill(['image_key' => $key])->save();
            $this->audit->record($key === null ? 'product.image_removed' : 'product.image_updated', $locked, actor: $actor);

            return $previous;
        });
    }
}

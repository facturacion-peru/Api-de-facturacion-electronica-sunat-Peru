<?php

namespace App\Images;

/** Foto del producto ya procesada: los bytes WebP de la grande y de la miniatura (spec 016). */
final readonly class ProcessedImage
{
    public function __construct(
        public string $large,
        public string $thumb,
    ) {}
}

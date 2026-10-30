<?php

namespace App\Images;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** El disco de imágenes (S3) no respondió al subir: 503 y nada cambia (spec 016, RF-010). */
class ImageStorageUnavailable extends HttpException
{
    public function __construct(string $message = 'No se pudo guardar la foto. Inténtalo de nuevo.', ?\Throwable $previous = null)
    {
        parent::__construct(503, $message, $previous);
    }
}

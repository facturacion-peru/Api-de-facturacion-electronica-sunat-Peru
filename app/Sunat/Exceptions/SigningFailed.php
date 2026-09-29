<?php

namespace App\Sunat\Exceptions;

use RuntimeException;

/** No se pudo firmar el XML (p. ej. certificado ilegible): no se emite ni se consume correlativo. */
class SigningFailed extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('No se pudo firmar el comprobante con el certificado registrado. Revisa la configuración SUNAT.', 0, $previous);
    }
}

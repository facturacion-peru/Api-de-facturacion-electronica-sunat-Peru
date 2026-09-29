<?php

namespace App\Enums;

/** Resultado de un intento de envío a SUNAT (RF-013). */
enum SubmissionResult: string
{
    case Accepted = 'accepted';
    case Observed = 'observed';
    case Rejected = 'rejected';
    /** SUNAT no respondió (red, tiempo agotado, 401 transitorio de beta): se reintenta. */
    case Unreachable = 'unreachable';
    /** Fallo propio antes de enviar (p. ej. XML ilegible): se reintenta a mano. */
    case Error = 'error';
}

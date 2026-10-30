<?php

namespace App\DocumentLookup;

/** Resultado de una consulta al servicio de DNI y RUC (spec 018). */
enum LookupStatus: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    // Servicio caído, tiempo agotado o token rechazado.
    case Unavailable = 'unavailable';
    // El servicio dice que se acabaron las consultas del día (A-96).
    case Quota = 'quota';
    // Sin token configurado: no se llama al servicio.
    case Disabled = 'disabled';

    /** Por qué no se pudo consultar, para quien atiende. */
    public function message(): string
    {
        return match ($this) {
            self::Found => 'Datos actualizados.',
            self::NotFound => 'El servicio no encontró este documento.',
            self::Unavailable => 'El servicio de consulta no responde. Inténtalo más tarde.',
            self::Quota => 'Se acabaron las consultas de hoy. Inténtalo mañana.',
            self::Disabled => 'La consulta de DNI y RUC no está activada.',
        };
    }
}

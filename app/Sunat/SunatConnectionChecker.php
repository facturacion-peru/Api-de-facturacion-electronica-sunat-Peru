<?php

namespace App\Sunat;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Comprueba que el servicio de SUNAT beta responde, leyendo su WSDL: no
 * envía ningún documento (plan 004). Sin respuesta o con error del servidor,
 * SUNAT se considera no disponible.
 */
class SunatConnectionChecker
{
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(config('services.sunat.timeout'))->get(config('services.sunat.beta_wsdl'));

            return $response->successful() && str_contains($response->body(), 'definitions');
        } catch (Throwable) {
            return false;
        }
    }
}

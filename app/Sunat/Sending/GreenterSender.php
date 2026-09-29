<?php

namespace App\Sunat\Sending;

use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Throwable;

/**
 * Envío real con Greenter al ambiente beta (A-20). En beta se usan las
 * credenciales genéricas de prueba de SUNAT (A-31), desde configuración.
 * El XML ya viene firmado: se envía tal cual, así los reintentos mandan
 * exactamente el mismo documento y el mismo hash.
 */
final class GreenterSender implements SunatSender
{
    public function send(string $signedXml, string $issuerRuc): SunatResponse
    {
        $see = new See;
        $see->setService(SunatEndpoints::FE_BETA);
        $see->setCredentials(
            str_replace('{ruc}', $issuerRuc, (string) config('services.sunat.beta_user')),
            (string) config('services.sunat.beta_password'),
        );

        $previous = ini_set('default_socket_timeout', (string) config('services.sunat.send_timeout'));

        try {
            return SunatResponse::fromGreenter($see->sendXmlFile($signedXml));
        } catch (Throwable $e) {
            return SunatResponse::unreachable($e->getMessage());
        } finally {
            ini_set('default_socket_timeout', (string) $previous);
        }
    }
}

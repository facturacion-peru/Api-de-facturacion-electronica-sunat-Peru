<?php

namespace App\Sunat\Sending;

/** Envío de un XML firmado a SUNAT. En pruebas se reemplaza por FakeSunatSender. */
interface SunatSender
{
    public function send(string $signedXml, string $issuerRuc): SunatResponse;
}

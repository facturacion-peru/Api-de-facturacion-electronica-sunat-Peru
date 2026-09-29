<?php

namespace App\Sunat;

use App\Sunat\Exceptions\SigningFailed;
use Greenter\Model\Sale\Invoice;
use Greenter\See;
use Throwable;

/**
 * Firma el XML UBL con el certificado de la empresa. El PEM (certificado y
 * clave privada) llega descifrado solo en memoria y nunca se escribe en
 * disco ni en logs (principio IV).
 */
final class DocumentSigner
{
    public function sign(Invoice $invoice, string $pem): SignedXml
    {
        try {
            $see = new See;
            $see->setCertificate($pem);
            $xml = $see->getXmlSigned($invoice);
        } catch (Throwable $e) {
            throw new SigningFailed($e);
        }

        if (! $xml || ! preg_match('/<ds:DigestValue>([^<]+)<\/ds:DigestValue>/', $xml, $match)) {
            throw new SigningFailed;
        }

        return new SignedXml($xml, $match[1]);
    }
}

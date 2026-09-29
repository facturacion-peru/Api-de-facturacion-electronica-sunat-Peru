<?php

namespace App\Sales\Exceptions;

use LogicException;

/** Un comprobante emitido no se edita ni se borra (RF-014); solo avanza su estado de envío. */
class SalesDocumentImmutable extends LogicException
{
    public function __construct()
    {
        parent::__construct('Los comprobantes emitidos no se modifican ni se borran.');
    }
}

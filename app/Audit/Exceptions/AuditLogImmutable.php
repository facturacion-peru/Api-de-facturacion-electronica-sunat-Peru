<?php

namespace App\Audit\Exceptions;

use LogicException;

class AuditLogImmutable extends LogicException
{
    public function __construct()
    {
        parent::__construct('Los registros de auditoría no se pueden modificar ni borrar.');
    }
}

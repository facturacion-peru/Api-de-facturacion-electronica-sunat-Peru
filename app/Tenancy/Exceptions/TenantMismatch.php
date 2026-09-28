<?php

namespace App\Tenancy\Exceptions;

use LogicException;

/** Se intentó escribir un registro de otra empresa distinta a la del contexto. */
class TenantMismatch extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("No se puede guardar {$model} en una empresa distinta a la del contexto.");
    }
}

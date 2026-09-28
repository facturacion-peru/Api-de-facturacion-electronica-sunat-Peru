<?php

namespace App\Tenancy\Exceptions;

use LogicException;

/**
 * Se consultó un modelo de empresa sin contexto de empresa. Es un error de
 * programación, no del usuario: nunca debe devolverse "todo" por defecto.
 */
class TenantContextMissing extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("No hay contexto de empresa para consultar {$model}. Usa TenantContext::run() o, en código de plataforma, withoutTenancy().");
    }
}

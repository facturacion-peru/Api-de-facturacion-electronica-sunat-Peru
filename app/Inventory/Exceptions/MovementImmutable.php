<?php

namespace App\Inventory\Exceptions;

use LogicException;

class MovementImmutable extends LogicException
{
    public function __construct()
    {
        parent::__construct('Los movimientos de inventario no se modifican ni se borran: se revierten.');
    }
}

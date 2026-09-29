<?php

namespace App\Inventory\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * No hay stock disponible suficiente (HU-3.4, RF-015). La API la responde
 * como 422 con `errors.quantity` y `meta.available` (bootstrap/app.php).
 */
class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
        public readonly string $requested,
        public readonly string $available,
    ) {
        parent::__construct("Stock insuficiente de {$product->name}: disponible {$available}.");
    }
}

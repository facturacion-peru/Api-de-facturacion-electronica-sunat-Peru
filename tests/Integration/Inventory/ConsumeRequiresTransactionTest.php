<?php

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;

/*
 * T040 · RF-017: descontar stock solo dentro de la transacción de la venta,
 * para que la venta y el descuento se confirmen o fallen juntos.
 */

it('rechaza descontar stock fuera de una transacción', function () {
    $product = Product::factory()->create();
    ProductLot::factory()->for($product)->quantity('5')->create();

    app(InventoryService::class)->consume($product, '1', null, User::factory()->create());
})->throws(LogicException::class, 'transacción');

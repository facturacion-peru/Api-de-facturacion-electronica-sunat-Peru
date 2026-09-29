<?php

use App\Models\ProductLot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * T015 · Última barrera contra el stock negativo (RF-015): el CHECK de
 * PostgreSQL rechaza un saldo negativo aunque se escriba fuera del servicio.
 */

it('PostgreSQL rechaza un saldo de lote negativo', function () {
    $lot = ProductLot::factory()->quantity('5')->create();

    DB::table('product_lots')->where('id', $lot->id)->update(['remaining_quantity' => '-0.001']);
})->throws(QueryException::class)
    ->skip(fn () => DB::getDriverName() !== 'pgsql', 'El CHECK solo existe en PostgreSQL');

<?php

use App\Enums\MovementType;
use App\Inventory\Exceptions\InsufficientStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * T042 · CE-002: varios procesos compiten por las últimas unidades. Solo en
 * PostgreSQL (bloqueos reales entre conexiones) y con pcntl.
 *
 * 6 procesos piden 3 unidades cada uno sobre un stock de 10 repartido en
 * dos lotes: deben vender exactamente 3 (9 unidades) y fallar los otros 3.
 */

it('las ventas simultáneas nunca venden más stock del que hay', function () {
    $product = Product::factory()->create();
    ProductLot::factory()->for($product)->quantity('6')->create(['received_at' => today()->subDay()]);
    ProductLot::factory()->for($product)->quantity('4')->create(['received_at' => today()]);
    $actor = User::factory()->create();

    // Como en una venta real: dentro del contexto de la empresa (lo heredan los hijos).
    app(TenantContext::class)->set($product->company);

    $dir = sys_get_temp_dir().'/concurrencia-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.0;
    $children = [];

    DB::disconnect();

    foreach (range(1, 6) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            // Proceso hijo: conexión propia, espera la señal de salida y compite.
            DB::purge();
            time_sleep_until($startAt);

            try {
                DB::transaction(function () use ($product, $actor) {
                    app(InventoryService::class)->consume($product->fresh(), '3', null, $actor);
                    usleep(100_000); // mantiene el bloqueo para forzar la espera de los demás
                });
                $result = 'vendido';
            } catch (InsufficientStock) {
                $result = 'sin-stock';
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
            }

            file_put_contents("{$dir}/{$i}", $result);
            // Termina sin ejecutar los cierres de PHPUnit del proceso padre.
            pcntl_exec('/bin/true');
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();
    $results = array_map(fn ($file) => file_get_contents($file), glob("{$dir}/*"));
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);

    $counts = array_count_values($results);

    expect($results)->toHaveCount(6)
        ->and($counts['vendido'] ?? 0)->toBe(3, 'Resultados: '.implode(', ', $results))
        ->and($counts['sin-stock'] ?? 0)->toBe(3)
        ->and($product->fresh()->stock())->toBe('1.000')
        ->and(ProductLot::withoutTenancy()->where('remaining_quantity', '<', 0)->count())->toBe(0)
        ->and(InventoryMovement::withoutTenancy()->where('type', MovementType::Sale)->sum('quantity'))->toEqual(-9);
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

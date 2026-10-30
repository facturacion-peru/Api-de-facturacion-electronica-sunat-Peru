<?php

use App\Inventory\Exceptions\InsufficientStock;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\Decimal;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * T013 · CE-004 (spec 017): traslados y ventas simultáneos dejan el stock
 * correcto y sin deadlocks. Solo en PostgreSQL (bloqueos reales entre
 * conexiones) y con pcntl.
 */

/**
 * Corre cada tarea en un proceso propio, todos a la vez, y devuelve lo que
 * informó cada uno.
 *
 * @param  list<callable(): string>  $tasks
 * @return list<string>
 */
function runConcurrently(array $tasks): array
{
    $dir = sys_get_temp_dir().'/traslados-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.0;
    $children = [];

    DB::disconnect();

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            time_sleep_until($startAt);

            try {
                $result = $task();
            } catch (InsufficientStock) {
                $result = 'sin-stock';
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
            }

            file_put_contents("{$dir}/{$i}", $result);
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

    return $results;
}

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->a = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->b = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    $this->actor = User::factory()->create();
    $this->stockIn = fn (Establishment $e) => Decimal::sum(ProductLot::withoutTenancy()
        ->where('product_id', $this->product->id)->where('establishment_id', $e->id)->pluck('remaining_quantity'));
    // Los hijos heredan el contexto, como en una petición real.
    app(TenantContext::class)->set($this->company);
});

it('un traslado y ventas simultáneas en el origen nunca sacan más de lo que hay', function () {
    ProductLot::factory()->for($this->product)->quantity('10')->create(['establishment_id' => $this->a->id, 'lot_number' => 'L-1']);
    $inventory = fn () => app(InventoryService::class);

    $results = runConcurrently([
        function () use ($inventory) {
            $inventory()->transfer($this->product->fresh(), $this->a, $this->b, '6', null, $this->actor);

            return 'trasladado';
        },
        ...array_fill(0, 3, function () use ($inventory) {
            DB::transaction(function () use ($inventory) {
                $inventory()->consume($this->product->fresh(), $this->a, '3', null, $this->actor);
                usleep(100_000);
            });

            return 'vendido';
        }),
    ]);

    $counts = array_count_values($results);
    $moved = ($counts['trasladado'] ?? 0) * 6;
    $sold = ($counts['vendido'] ?? 0) * 3;

    expect(array_filter($results, fn ($r) => str_starts_with($r, 'error')))->toBe([], 'Resultados: '.implode(', ', $results))
        ->and($moved + $sold)->toBeLessThanOrEqual(10)
        ->and(($this->stockIn)($this->a))->toBe(Decimal::fromDb((string) (10 - $moved - $sold), 3))
        ->and(($this->stockIn)($this->b))->toBe(Decimal::fromDb((string) $moved, 3))
        ->and(ProductLot::withoutTenancy()->where('remaining_quantity', '<', 0)->count())->toBe(0);
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

it('traslados cruzados A→B y B→A al mismo tiempo terminan todos, sin deadlock', function () {
    ProductLot::factory()->for($this->product)->quantity('20')->create(['establishment_id' => $this->a->id, 'lot_number' => 'L-1']);
    ProductLot::factory()->for($this->product)->quantity('20')->create(['establishment_id' => $this->b->id, 'lot_number' => 'L-1']);
    $transfer = fn (Establishment $from, Establishment $to) => function () use ($from, $to) {
        app(InventoryService::class)->transfer($this->product->fresh(), $from, $to, '2', null, $this->actor);

        return 'trasladado';
    };

    $results = runConcurrently([
        ...array_fill(0, 4, $transfer($this->a, $this->b)),
        ...array_fill(0, 4, $transfer($this->b, $this->a)),
    ]);

    expect($results)->toBe(array_fill(0, 8, 'trasladado'), 'Resultados: '.implode(', ', $results))
        ->and(($this->stockIn)($this->a))->toBe('20.000')
        ->and(($this->stockIn)($this->b))->toBe('20.000');
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

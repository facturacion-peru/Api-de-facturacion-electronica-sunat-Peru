<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * T024 · CE-003: con ventas simultáneas, la numeración queda sin huecos ni
 * duplicados. Solo en PostgreSQL (bloqueos reales entre conexiones) y con pcntl.
 */

it('las ventas simultáneas reciben números consecutivos sin huecos ni duplicados', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $seller = User::factory()->forCompany($company)->create();
    $service = Product::factory()->service()->create(['company_id' => $company->id]);
    app(TenantContext::class)->set($company);

    $dir = sys_get_temp_dir().'/tickets-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.0;
    $children = [];

    DB::disconnect();

    foreach (range(1, 6) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            time_sleep_until($startAt);

            try {
                [$ticket] = app(TicketService::class)->issue([
                    'idempotency_key' => (string) Str::uuid(),
                    'payment_method' => 'cash',
                    'lines' => [['product_id' => $service->id, 'quantity' => '1']],
                ], $seller->fresh());
                $result = (string) $ticket->number;
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

    $numbers = array_map('intval', $results);
    sort($numbers);

    expect($numbers)->toBe([1, 2, 3, 4, 5, 6], 'Resultados: '.implode(', ', $results))
        ->and(Ticket::withoutTenancy()->pluck('number')->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6]);
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

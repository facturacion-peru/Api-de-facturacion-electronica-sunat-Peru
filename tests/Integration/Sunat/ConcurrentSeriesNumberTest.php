<?php

use App\Models\Series;
use App\Services\SeriesService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * T042 · CE-003: con emisiones simultáneas sobre una misma serie, los
 * correlativos no se repiten ni saltan. Solo PostgreSQL y pcntl.
 */

it('los correlativos simultáneos son consecutivos y sin repetir', function () {
    $series = Series::factory()->create(['last_number' => 150]);
    app(TenantContext::class)->set($series->company);

    $dir = sys_get_temp_dir().'/series-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.0;
    $children = [];

    DB::disconnect();

    foreach (range(1, 8) as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            time_sleep_until($startAt);

            try {
                $number = DB::transaction(function () use ($series) {
                    $number = app(SeriesService::class)->nextNumber($series);
                    usleep(50_000); // mantiene el bloqueo para forzar la espera

                    return $number;
                });
                $result = (string) $number;
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

    expect($numbers)->toBe(range(151, 158), 'Resultados: '.implode(', ', $results))
        ->and(Series::withoutTenancy()->find($series->id)->last_number)->toBe(158);
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

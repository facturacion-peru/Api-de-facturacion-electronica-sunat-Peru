<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

/*
 * Tareas programadas. En el servidor, cron con `php artisan schedule:run`
 * cada minuto; en desarrollo, `php artisan schedule:work`.
 */

/** Ping a un monitor de latidos externo si está configurado (spec 009, A-48). */
$ping = fn (string $key) => function () use ($key) {
    if ($url = config("ops.{$key}")) {
        rescue(fn () => Http::timeout(10)->get($url), report: false);
    }
};

// Reintentos automáticos de envío a SUNAT (spec 005). También es el latido
// del scheduler: si el cron muere, el monitor deja de recibir el ping.
Schedule::command('sunat:send-pending')->everyMinute()->withoutOverlapping()
    ->onSuccess($ping('heartbeat_url'));

// Vistas previas de importación caducadas (spec 014).
Schedule::command('model:prune', ['--model' => [App\Models\ImportPreview::class]])->dailyAt('02:30')->withoutOverlapping();

// Operación del servidor (spec 009): solo en staging y producción.
$servers = ['production', 'staging'];

Schedule::command('backup:run')->dailyAt('03:00')->withoutOverlapping()->environments($servers)
    ->onSuccess($ping('backup_ping_url'));
Schedule::command('backup:clean')->dailyAt('03:30')->withoutOverlapping()->environments($servers);
Schedule::command('backup:monitor')->dailyAt('04:00')->environments($servers);
Schedule::command('ops:check')->everyFifteenMinutes()->withoutOverlapping()->environments($servers);
Schedule::command('ops:restore-test')->monthlyOn(1, '05:00')->withoutOverlapping()->environments($servers);

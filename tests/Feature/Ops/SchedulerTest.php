<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

/*
 * T015 · Tareas programadas de operación (spec 009, A-45, A-48).
 */

function scheduled(string $command): ?Illuminate\Console\Scheduling\Event
{
    return collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, $command));
}

it('programa cada tarea con su frecuencia', function (string $command, string $expression, array $environments) {
    $event = scheduled($command);

    expect($event)->not->toBeNull("{$command} no está programado")
        ->and($event->expression)->toBe($expression)
        ->and($event->environments)->toBe($environments);
})->with([
    'reenvío a SUNAT (y latido)' => ['sunat:send-pending', '* * * * *', []],
    'copia diaria' => ['backup:run', '0 3 * * *', ['production', 'staging']],
    'limpieza de copias' => ['backup:clean', '30 3 * * *', ['production', 'staging']],
    'monitoreo de copias' => ['backup:monitor', '0 4 * * *', ['production', 'staging']],
    'revisión de operación' => ['ops:check', '*/15 * * * *', ['production', 'staging']],
]);

// schedule:run ejecuta cada comando en otro proceso (que no ve la base en memoria
// de la prueba): aquí se disparan los callbacks del evento como lo hace el
// scheduler cuando el comando termina con un código de salida dado.

it('el latido llega al monitor cuando el reenvío termina bien', function () {
    Http::fake();
    config(['ops.heartbeat_url' => 'https://latidos.test/ping/abc']);

    scheduled('sunat:send-pending')->finish(app(), 0);

    Http::assertSent(fn ($request) => $request->url() === 'https://latidos.test/ping/abc');
});

it('si el comando falla no hay latido, y el monitor avisa', function () {
    Http::fake();
    config(['ops.heartbeat_url' => 'https://latidos.test/ping/abc']);

    scheduled('sunat:send-pending')->finish(app(), 1);

    Http::assertNothingSent();
});

it('sin monitor configurado no hace ninguna llamada', function () {
    Http::fake();
    config(['ops.heartbeat_url' => null]);

    scheduled('sunat:send-pending')->finish(app(), 0);

    Http::assertNothingSent();
});

it('la copia avisa a su monitor solo si termina bien', function () {
    Http::fake();
    config(['ops.backup_ping_url' => 'https://latidos.test/ping/copia']);

    scheduled('backup:run')->finish(app(), 1);
    Http::assertNothingSent();

    scheduled('backup:run')->finish(app(), 0);
    Http::assertSent(fn ($request) => $request->url() === 'https://latidos.test/ping/copia');
});

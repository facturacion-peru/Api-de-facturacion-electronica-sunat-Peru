<?php

use App\Notifications\OpsAlert;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
 * T014 · Errores 500 repetidos (spec 009, A-48): 5 iguales en 10 minutos →
 * un correo; como máximo uno por error cada hora; sin el mensaje del error.
 */

beforeEach(function () {
    Notification::fake();
    config(['ops.alert_email' => 'ops@saas.test']);
    Route::get('/api/v1/__prueba-error', fn () => throw new RuntimeException('clave SOL Sol-Secreta-123 del cliente'));
    Route::get('/api/v1/__prueba-otro-error', fn () => throw new LogicException('otro'));
    $this->boom = fn (string $uri = '/api/v1/__prueba-error') => $this->getJson($uri)->assertStatus(500);
});

it('cuatro errores no avisan; el quinto sí, una sola vez', function () {
    foreach (range(1, 4) as $_) {
        ($this->boom)();
    }
    Notification::assertNothingSent();

    ($this->boom)();
    ($this->boom)();

    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);
});

it('fuera de la ventana de 10 minutos la cuenta vuelve a empezar', function () {
    foreach (range(1, 4) as $_) {
        ($this->boom)();
    }
    $this->travel(11)->minutes();
    ($this->boom)();

    Notification::assertNothingSent();
});

it('vuelve a avisar pasada una hora si el error sigue', function () {
    foreach (range(1, 5) as $_) {
        ($this->boom)();
    }
    $this->travel(61)->minutes();
    foreach (range(1, 5) as $_) {
        ($this->boom)();
    }

    Notification::assertSentOnDemandTimes(OpsAlert::class, 2);
});

it('cada error distinto se cuenta por separado', function () {
    foreach (range(1, 3) as $_) {
        ($this->boom)();
        ($this->boom)('/api/v1/__prueba-otro-error');
    }

    Notification::assertNothingSent();
});

it('el aviso identifica el error por clase y lugar, sin su mensaje', function () {
    foreach (range(1, 5) as $_) {
        ($this->boom)();
    }

    Notification::assertSentOnDemand(OpsAlert::class, function (OpsAlert $alert) {
        return str_contains($alert->detail, 'RuntimeException')
            && str_contains($alert->detail, 'ErrorAlertTest.php')
            && ! str_contains($alert->detail, 'Sol-Secreta-123');
    });
});

it('los errores esperados (404, 422) no cuentan', function () {
    foreach (range(1, 6) as $_) {
        $this->getJson('/api/v1/no-existe')->assertNotFound();
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
    }

    Notification::assertNothingSent();
});

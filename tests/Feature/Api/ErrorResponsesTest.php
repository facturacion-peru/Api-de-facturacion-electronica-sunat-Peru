<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;

/*
 * T010 · Todas las rutas api/* responden los errores con el mismo sobre:
 * { success: false, message, errors? }, sin trazas ni detalles internos.
 */

beforeEach(function () {
    Route::middleware('api')->prefix('api/_pruebas')->group(function () {
        Route::get('/protegida', fn () => 'ok')->middleware('auth:sanctum');
        Route::get('/prohibida', fn () => throw new AuthorizationException);
        Route::post('/validada', function () {
            request()->validate(['nombre' => 'required']);
        });
        Route::get('/limitada', fn () => 'ok')->middleware('throttle:1,1');
        Route::get('/rota', fn () => throw new RuntimeException('detalle interno secreto'));
    });
});

function expectErrorEnvelope($response, int $status): void
{
    $response->assertStatus($status)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message']);

    expect($response->json('message'))->toBeString()->not->toBeEmpty();
    expect($response->json())->not->toHaveKeys(['exception', 'file', 'line', 'trace']);
}

it('responde 401 con el sobre de error sin sesión', function () {
    expectErrorEnvelope($this->getJson('/api/_pruebas/protegida'), 401);
});

it('responde 401 aunque el cliente no pida JSON', function () {
    expectErrorEnvelope($this->get('/api/_pruebas/protegida'), 401);
});

it('responde 403 con el sobre de error', function () {
    expectErrorEnvelope($this->getJson('/api/_pruebas/prohibida'), 403);
});

it('responde 404 con el sobre de error para rutas inexistentes', function () {
    expectErrorEnvelope($this->getJson('/api/_pruebas/no-existe'), 404);
});

it('responde 422 con los errores por campo', function () {
    $response = $this->postJson('/api/_pruebas/validada', []);

    expectErrorEnvelope($response, 422);
    $response->assertJsonStructure(['errors' => ['nombre']]);
});

it('responde 429 con el sobre de error', function () {
    $this->getJson('/api/_pruebas/limitada')->assertOk();

    expectErrorEnvelope($this->getJson('/api/_pruebas/limitada'), 429);
});

it('responde 500 sin revelar el detalle interno', function () {
    $response = $this->getJson('/api/_pruebas/rota');

    expectErrorEnvelope($response, 500);
    expect($response->getContent())->not->toContain('detalle interno secreto');
});

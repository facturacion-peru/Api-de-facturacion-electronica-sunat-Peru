<?php

/*
 * T093 · RF-051: CORS solo admite los orígenes configurados (por defecto,
 * el frontend), nunca «*».
 */

function preflight(string $origin)
{
    return test()->call('OPTIONS', '/api/v1/auth/login', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);
}

it('admite el origen del frontend', function () {
    preflight(config('app.frontend_url'))
        ->assertHeader('Access-Control-Allow-Origin', config('app.frontend_url'));
});

it('no admite orígenes desconocidos ni responde con comodín', function () {
    $response = preflight('https://sitio-malicioso.example');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*')
        ->not->toBe('https://sitio-malicioso.example');
});

it('la lista de orígenes no contiene comodines', function () {
    expect(config('cors.allowed_origins'))->not->toContain('*')
        ->and(config('cors.allowed_origins_patterns'))->toBe([]);
});

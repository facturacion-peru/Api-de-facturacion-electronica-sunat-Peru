<?php

/*
| CORS (RF-051). Solo los orígenes de CORS_ALLOWED_ORIGINS (separados por
| comas); por defecto, el frontend (FRONTEND_URL). Nunca «*».
|
| En desarrollo el frontend llama por el proxy de Vite (mismo origen) y CORS
| ni siquiera interviene; esto importa cuando API y frontend viven en
| dominios distintos.
*/

$origins = array_values(array_filter(array_map(
    fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', (string) (env('CORS_ALLOWED_ORIGINS') ?: env('FRONTEND_URL', 'http://localhost:5173'))),
), fn (string $origin) => $origin !== '' && $origin !== '*'));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-App-Version'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    // Autenticación por token Bearer: no hacen falta cookies entre orígenes.
    'supports_credentials' => false,

];

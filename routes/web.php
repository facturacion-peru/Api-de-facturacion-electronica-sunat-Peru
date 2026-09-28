<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Documentación interactiva de la API (Swagger UI).
// La especificación se regenera con: php artisan openapi:generate
Route::get('/docs', function () {
    abort_unless(file_exists(public_path('openapi.json')), 404, 'Ejecuta: php artisan openapi:generate');

    return view('docs');
});

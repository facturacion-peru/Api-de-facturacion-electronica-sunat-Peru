<?php

/*
 * El generador de OpenAPI distingue objetos de listas (spec 006): una regla
 * 'array' con claves con nombre es un objeto; la lista se declara con '.*'.
 */

it('documenta objetos anidados como objeto y listas como lista', function () {
    $target = tempnam(sys_get_temp_dir(), 'openapi-');
    $this->artisan('openapi:generate', ['--output' => $target])->assertSuccessful();
    $spec = json_decode(file_get_contents($target), true);
    unlink($target);

    $body = fn (string $path, string $method) => $spec['paths'][$path][$method]['requestBody']['content']['application/json']['schema']['properties'];

    expect($body('/api/v1/platform/companies/{company}', 'patch')['fiscal_address'])
        ->toMatchArray(['type' => 'object'])
        ->toHaveKey('properties.ubigeo')
        ->not->toHaveKey('items')
        ->and($body('/api/v1/sales-documents', 'post')['lines']['type'])->toBe('array')
        ->and($body('/api/v1/sales-documents', 'post')['lines']['items']['properties'])->toHaveKey('product_id');
});

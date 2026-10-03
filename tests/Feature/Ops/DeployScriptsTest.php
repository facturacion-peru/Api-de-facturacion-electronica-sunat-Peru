<?php

use Illuminate\Support\Facades\Process;

/*
 * T021 · Los scripts de despliegue (deploy/release.sh y rollback.sh) se
 * prueban en una carpeta temporal con deploy/tests/release_test.sh: así
 * corren también en la suite y en CI.
 */

it('release.sh y rollback.sh pasan sus pruebas', function () {
    $result = Process::timeout(120)->run(['bash', base_path('deploy/tests/release_test.sh')]);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('Todas las pruebas de despliegue pasaron.');
})->skip(fn () => PHP_OS_FAMILY !== 'Linux', 'Los scripts de despliegue son para el servidor Linux');

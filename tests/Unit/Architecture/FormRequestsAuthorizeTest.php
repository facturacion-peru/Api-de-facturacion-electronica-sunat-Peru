<?php

/*
 * T023 · Reglas de arquitectura que protegen la seguridad (principio IV):
 * - Ningún FormRequest autoriza con un `return true` incondicional.
 * - Fuera de Requests/Auth (flujos públicos de autenticación, constitución
 *   1.1.1), todo FormRequest declara authorize().
 * - Ningún Log:: recibe getChanges() ni el cuerpo completo de la petición,
 *   porque ahí viajan contraseñas y secretos (hallazgo H-3 de la evaluación).
 */

function phpFilesIn(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    return array_values(array_filter(
        iterator_to_array($files),
        fn (SplFileInfo $file) => $file->isFile() && $file->getExtension() === 'php',
    ));
}

it('ningún FormRequest autoriza incondicionalmente', function () {
    $offenders = [];

    foreach (phpFilesIn(dirname(__DIR__, 3).'/app/Http/Requests') as $file) {
        $code = file_get_contents($file->getPathname());

        if (preg_match('/function\s+authorize\s*\([^)]*\)\s*(?::\s*bool\s*)?\{\s*return\s+true\s*;\s*\}/i', $code)) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([], 'authorize() debe delegar en una Policy o en el rol: '.implode(', ', $offenders));
});

it('todo FormRequest fuera de Auth declara authorize()', function () {
    $offenders = [];
    $base = dirname(__DIR__, 3).'/app/Http/Requests';

    foreach (phpFilesIn($base) as $file) {
        $relative = substr($file->getPathname(), strlen($base) + 1);

        if (str_starts_with($relative, 'Auth/')) {
            continue;
        }

        if (! preg_match('/function\s+authorize\s*\(/', file_get_contents($file->getPathname()))) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'Falta authorize() en: '.implode(', ', $offenders));
});

it('ningún log recibe getChanges() ni el cuerpo completo de la petición', function () {
    $offenders = [];

    foreach (phpFilesIn(dirname(__DIR__, 3).'/app') as $file) {
        $code = file_get_contents($file->getPathname());

        preg_match_all('/(?:Log::|logger\()[^;]*;/s', $code, $calls);

        foreach ($calls[0] as $call) {
            if (preg_match('/getChanges\(|getDirty\(|getAttributes\(|->all\(\)|request\(\)->input\(\)/', $call)) {
                $offenders[] = $file->getFilename();
            }
        }
    }

    expect($offenders)->toBe([], 'No registrar cambios o peticiones completas en el log: '.implode(', ', array_unique($offenders)));
});

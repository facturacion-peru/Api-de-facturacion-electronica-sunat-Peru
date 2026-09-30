<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * T012 · Copias de seguridad (spec 009, A-45): base y logos, cifradas, en
 * S3, con 30 copias diarias y 12 semanales.
 */

beforeEach(function () {
    config(['backup.backup.password' => 'clave-de-prueba-de-copias']);
    // El paquete guarda su configuración al arrancar (instancia scoped): se
    // descarta para que tome la clave. En el servidor llega por el .env.
    app()->forgetInstance(Spatie\Backup\Config\Config::class);
});

it('copia la base y los logos, cifrados, al disco s3 con avisos solo de fallas', function () {
    expect(config('backup.backup.source.databases'))->toBe([config('database.default')])
        ->and(config('backup.backup.source.files.include'))->toBe([storage_path('app/public')])
        ->and(config('backup.backup.destination.disks'))->toBe(['s3'])
        ->and(config('backup.backup.encryption'))->toBe('default')
        ->and(array_filter(config('backup.notifications.notifications')))->toHaveCount(3)
        ->and(config('backup.cleanup.default_strategy'))->toMatchArray([
            'keep_all_backups_for_days' => 0, 'keep_daily_backups_for_days' => 30, 'keep_weekly_backups_for_weeks' => 12,
            'keep_monthly_backups_for_months' => 0, 'keep_yearly_backups_for_years' => 0,
        ]);
});

it('la limpieza deja 30 diarias y 12 semanales', function () {
    Storage::fake('s3');
    Carbon::setTestNow('2026-09-30 03:30:00');
    foreach (range(0, 119) as $daysAgo) {
        Storage::disk('s3')->put('sunat-testing/'.now()->subDays($daysAgo)->setTime(3, 0)->format('Y-m-d-H-i-s').'.zip', 'copia');
    }

    $this->artisan('backup:clean', ['--disable-notifications' => true])->assertSuccessful();

    $left = collect(Storage::disk('s3')->files('sunat-testing'))->sort()->values();
    $daily = $left->filter(fn ($f) => str_contains($f, now()->subDays(29)->format('Y-m-d')) || $f >= 'sunat-testing/'.now()->subDays(29)->format('Y-m-d'));

    expect($left->count())->toBeGreaterThanOrEqual(41)->toBeLessThanOrEqual(43)
        ->and($daily->count())->toBe(30)
        ->and($left->last())->toBe('sunat-testing/2026-09-30-03-00-00.zip');
});

it('la copia real incluye la base y los logos y está cifrada (PostgreSQL)', function () {
    Storage::fake('s3');
    $logo = storage_path('app/public/prueba-copia-logo.png');
    @mkdir(dirname($logo), 0775, true);
    file_put_contents($logo, 'logo');

    try {
        $this->artisan('backup:run', ['--disable-notifications' => true])->assertSuccessful();
    } finally {
        @unlink($logo);
    }

    $zipPath = Storage::disk('s3')->path(collect(Storage::disk('s3')->files('sunat-testing'))->sole());
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
    // Los archivos van cifrados con AES-256; las entradas de carpeta de un zip nunca se cifran.
    $encrypted = collect(range(0, $zip->numFiles - 1))->reject(fn ($i) => str_ends_with($zip->getNameIndex($i), '/'))
        ->every(fn ($i) => $zip->statIndex($i)['encryption_method'] === ZipArchive::EM_AES_256);

    expect($names->contains(fn ($n) => str_starts_with($n, 'db-dumps/postgresql-')))->toBeTrue($names->implode(', '))
        ->and($names)->toContain('public/prueba-copia-logo.png') // rutas relativas, sin la del servidor
        ->and($encrypted)->toBeTrue()
        ->and($zip->getFromName($names->first(fn ($n) => str_ends_with($n, 'prueba-copia-logo.png'))))->toBeFalse(); // sin la clave no se lee
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'La base en memoria de SQLite no se puede volcar');

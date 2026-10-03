<?php

use App\Enums\SunatStatus;
use App\Models\Company;
use App\Models\SunatSetting;
use App\Notifications\OpsAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * T022 · Restauración de prueba (spec 009, A-45): con datos confirmados en la
 * base (suite Integration, sin transacción envolvente) para que pg_dump los vea.
 */

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Restaura con psql: solo PostgreSQL');
    }
    Storage::fake('s3');
    Notification::fake();
    config(['backup.backup.password' => 'clave-de-prueba-de-copias', 'ops.alert_email' => 'ops@saas.test']);
    app()->forgetInstance(Spatie\Backup\Config\Config::class);
});

it('restaura la copia en una base temporal, descifra los secretos y la borra', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    SunatSetting::create(['company_id' => $company->id, 'environment' => 'beta', 'status' => SunatStatus::Pending, 'sol_user' => 'U', 'sol_password' => 'Clave-Sol-Restaurada']);
    $this->artisan('backup:run', ['--disable-notifications' => true, '--only-db' => true])->assertSuccessful();

    $this->artisan('ops:restore-test')->assertSuccessful();

    $database = config('database.connections.pgsql.database').'_restore_test';
    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $a) => $a->title === 'Restauración de prueba correcta'
        && str_contains($a->detail, '1 empresas') && str_contains($a->detail, 'secretos SUNAT descifrables'));
    expect(DB::select('SELECT 1 FROM pg_database WHERE datname = ?', [$database]))->toBe([]);
});

it('avisa si la clave de las copias no es la correcta', function () {
    $this->artisan('backup:run', ['--disable-notifications' => true, '--only-db' => true])->assertSuccessful();
    config(['backup.backup.password' => 'otra-clave']);
    app()->forgetInstance(Spatie\Backup\Config\Config::class);

    $this->artisan('ops:restore-test')->assertFailed();

    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $a) => $a->title === 'Restauración de prueba FALLIDA' && str_contains($a->detail, 'BACKUP_ARCHIVE_PASSWORD'));
});

it('avisa si no hay copias', function () {
    $this->artisan('ops:restore-test')->assertFailed();

    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $a) => str_contains($a->detail, 'No hay copias'));
});

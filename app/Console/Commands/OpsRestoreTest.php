<?php

namespace App\Console\Commands;

use App\Notifications\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Spatie\Backup\BackupDestination\BackupDestination;
use Throwable;
use ZipArchive;

/**
 * Restauración de prueba mensual (spec 009, A-45, CE-001): baja la copia
 * más reciente, la descifra, la restaura en una base temporal y comprueba
 * que la aplicación la puede usar (tablas y secretos SUNAT descifrables con
 * la APP_KEY actual). Luego borra la base temporal y avisa el resultado.
 * Nunca toca la base de datos de la aplicación.
 */
class OpsRestoreTest extends Command
{
    protected $signature = 'ops:restore-test {--keep : No borrar la base temporal (para revisarla a mano)}';

    protected $description = 'Restaura la copia más reciente en una base temporal y verifica que sirve';

    public function handle(): int
    {
        $work = storage_path('app/restore-test-'.now()->format('YmdHis'));
        $database = config('database.connections.pgsql.database').'_restore_test';

        try {
            $summary = $this->restoreAndVerify($work, $database);
            $this->info($summary);
            $this->notify('Restauración de prueba correcta', $summary);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->notify('Restauración de prueba FALLIDA', $e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
            if (! $this->option('keep')) {
                rescue(fn () => DB::connection('pgsql')->statement("DROP DATABASE IF EXISTS \"{$database}\""), report: false);
            }
        }
    }

    private function restoreAndVerify(string $work, string $database): string
    {
        $destination = BackupDestination::create(config('backup.backup.destination.disks')[0], config('backup.backup.name'));
        $backup = $destination->newestBackup() ?? throw new RuntimeException('No hay copias en el disco de copias.');

        File::ensureDirectoryExists($work);
        $zipPath = "{$work}/copia.zip";
        file_put_contents($zipPath, stream_get_contents($backup->stream()));

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('El archivo de la copia está dañado.');
        }
        $zip->setPassword((string) config('backup.backup.password'));
        $dumpName = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))
            ->first(fn ($name) => str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql'))
            ?? throw new RuntimeException('La copia no incluye el volcado de la base de datos.');
        if (! $zip->extractTo($work, $dumpName)) {
            throw new RuntimeException('No se pudo descifrar la copia: revisa BACKUP_ARCHIVE_PASSWORD.');
        }

        $admin = DB::connection('pgsql');
        $admin->statement("DROP DATABASE IF EXISTS \"{$database}\"");
        $admin->statement("CREATE DATABASE \"{$database}\"");

        $pg = config('database.connections.pgsql');
        $load = Process::env(['PGPASSWORD' => (string) $pg['password']])->timeout(600)->run([
            'psql', '--quiet', '--set=ON_ERROR_STOP=1', '-h', $pg['host'], '-p', (string) $pg['port'], '-U', $pg['username'],
            '-d', $database, '-f', "{$work}/{$dumpName}",
        ]);
        if ($load->failed()) {
            throw new RuntimeException('No se pudo cargar el volcado: '.trim($load->errorOutput()));
        }

        Config::set('database.connections.restore_test', [...$pg, 'database' => $database]);
        $restored = DB::connection('restore_test');
        $companies = $restored->table('companies')->count();
        $documents = $restored->table('sales_documents')->count();

        // Los secretos SUNAT solo sirven si la APP_KEY actual los descifra.
        $secret = $restored->table('sunat_settings')->whereNotNull('sol_password')->value('sol_password');
        if ($secret !== null) {
            Crypt::decryptString($secret);
        }
        DB::purge('restore_test');

        return "Copia del {$backup->date()->format('d/m/Y H:i')} restaurada en una base temporal: {$companies} empresas, {$documents} comprobantes"
            .($secret !== null ? ', secretos SUNAT descifrables con la APP_KEY actual.' : ' (sin secretos SUNAT que comprobar).');
    }

    private function notify(string $title, string $detail): void
    {
        rescue(fn () => Notification::route('mail', config('ops.alert_email'))->notify(new OpsAlert('restore-test', $title, $detail)), report: false);
    }
}

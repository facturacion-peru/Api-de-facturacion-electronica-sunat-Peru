<?php

use App\DataTransfer\Imports\ImportApplier;
use App\DataTransfer\Imports\ImportKinds;
use App\DataTransfer\Imports\RowImport;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\ImportPreview;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Spec 014 · T021: la confirmación es todo o nada también entre procesos.
 * Solo en PostgreSQL (bloqueos reales entre conexiones) y con pcntl.
 */

/** Tipo de importación que tarda en cada fila, para que las transacciones se crucen. */
function slowKinds(int $microseconds): ImportKinds
{
    return new class($microseconds) extends ImportKinds
    {
        public function __construct(private int $pause) {}

        public function for(string $kind): RowImport
        {
            $inner = parent::for($kind);

            return new class($inner, $this->pause) implements RowImport
            {
                public function __construct(private RowImport $inner, private int $pause) {}

                public function requiredColumns(): array
                {
                    return $this->inner->requiredColumns();
                }

                public function knownColumns(): array
                {
                    return $this->inner->knownColumns();
                }

                public function readOnlyColumns(): array
                {
                    return $this->inner->readOnlyColumns();
                }

                public function analyze(array $records, array $columns, string $mode): App\DataTransfer\Imports\Analysis
                {
                    return $this->inner->analyze($records, $columns, $mode);
                }

                public function assertStillNew(array $rows): void
                {
                    $this->inner->assertStillNew($rows);
                }

                public function apply(array $row, User $actor, array &$result): void
                {
                    usleep($this->pause);
                    $this->inner->apply($row, $actor, $result);
                }
            };
        }
    };
}

/**
 * Ejecuta cada tarea en un proceso hijo con su propia conexión, todas a la vez.
 *
 * @param  array<string, Closure(): string>  $tasks
 * @return array<string, string>
 */
function inParallel(array $tasks): array
{
    $dir = sys_get_temp_dir().'/importacion-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.0;
    $children = [];
    DB::disconnect();

    foreach ($tasks as $name => $task) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            time_sleep_until($startAt);
            try {
                $result = $task();
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
            }
            file_put_contents("{$dir}/{$name}", $result);
            pcntl_exec('/bin/true');
        }
        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::reconnect();

    $results = [];
    foreach (glob("{$dir}/*") as $file) {
        $results[basename($file)] = file_get_contents($file);
        unlink($file);
    }
    rmdir($dir);

    return $results;
}

/** Confirma en el proceso actual y devuelve el resultado como texto. */
function confirmAs(ImportPreview $preview, User $user, int $pause): string
{
    app()->instance(ImportKinds::class, slowKinds($pause));
    app()->forgetInstance(ImportApplier::class);

    try {
        $result = app(ImportApplier::class)->confirm($preview->fresh(), $user);

        return 'aplicado '.$result['created'];
    } catch (HttpException $e) {
        return (string) $e->getStatusCode();
    }
}

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    app(TenantContext::class)->set($this->company);

    $rows = [];
    foreach (range(1, 10) as $i) {
        $rows[] = ['row' => $i + 1, 'action' => 'create', 'entry' => ['quantity' => '5', 'unit_cost' => null, 'lot_number' => null, 'expires_at' => null], 'data' => [
            'code' => "IMP-{$i}", 'name' => "Producto {$i}", 'type' => 'good', 'unit' => 'NIU', 'sale_price' => '1.00',
            'igv_affectation' => '10', 'tracks_expiry' => false, 'active' => true,
        ]];
    }
    $this->preview = ImportPreview::create([
        'user_id' => $this->admin->id, 'kind' => 'products', 'mode' => 'create', 'rows' => $rows, 'summary' => ['rows' => 10],
        'errors' => [], 'warnings' => [], 'changes' => [], 'expires_at' => now()->addMinutes(30),
    ]);
});

it('una confirmación doble simultánea aplica una sola vez; la otra recibe 410', function () {
    $results = inParallel([
        'a' => fn () => confirmAs($this->preview, $this->admin, 50_000),
        'b' => fn () => confirmAs($this->preview, $this->admin, 50_000),
    ]);

    expect(collect($results)->sort()->values()->all())->toBe(['410', 'aplicado 10'], 'Resultados: '.json_encode($results))
        ->and(Product::query()->where('code', 'like', 'IMP-%')->count())->toBe(10)
        ->and(ProductLot::query()->count())->toBe(10);
})->skip(fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'), 'Requiere PostgreSQL y pcntl');

it('un producto creado por otra petición durante la confirmación la cancela entera (409), sin filas a medias', function () {
    $results = inParallel([
        'importacion' => fn () => confirmAs($this->preview, $this->admin, 80_000),
        'alta manual' => function () {
            usleep(250_000); // con la importación ya en curso, antes de llegar a la última fila
            app(TenantContext::class)->set($this->company);
            Product::factory()->create(['company_id' => $this->company->id, 'code' => 'IMP-10']);

            return 'creado';
        },
    ]);

    expect($results)->toBe(['alta manual' => 'creado', 'importacion' => '409'], 'Resultados: '.json_encode($results))
        ->and(Product::query()->where('code', 'like', 'IMP-%')->pluck('code')->all())->toBe(['IMP-10'])
        ->and(ProductLot::query()->count())->toBe(0)
        ->and($this->preview->fresh()->confirmed_at)->toBeNull();
})->skip(fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'), 'Requiere PostgreSQL y pcntl');

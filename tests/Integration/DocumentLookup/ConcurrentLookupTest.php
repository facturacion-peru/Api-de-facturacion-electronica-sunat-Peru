<?php

use App\DocumentLookup\DocumentLookupService;
use App\Enums\CustomerDocumentType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentLookupRequest;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * T009 · CE-004 (spec 018): dos búsquedas simultáneas del mismo documento
 * nuevo hacen una sola llamada al servicio y dejan un solo cliente. Solo en
 * PostgreSQL (procesos con conexiones propias) y con pcntl.
 */

it('dos búsquedas simultáneas del mismo documento gastan una sola consulta', function () {
    // El lock vive en el caché de la base: compartido entre procesos, como en producción.
    config(['cache.default' => 'database', 'services.apisperu.token' => 'token-de-prueba', 'services.apisperu.base_url' => 'https://dniruc.test/api/v1']);
    Http::fake(function () {
        usleep(300_000); // el servicio tarda: la segunda búsqueda llega mientras tanto

        return Http::response(['ruc' => '20131312955', 'razonSocial' => 'SUNAT', 'estado' => 'ACTIVO', 'condicion' => 'HABIDO', 'direccion' => 'AV. GARCILASO 1472']);
    });

    $company = Company::factory()->withMainEstablishment()->create();
    $sellers = [User::factory()->forCompany($company)->create(), User::factory()->forCompany($company)->create()];
    app(TenantContext::class)->set($company);

    $dir = sys_get_temp_dir().'/consulta-'.uniqid();
    mkdir($dir);
    $startAt = microtime(true) + 1.0;
    $children = [];

    DB::disconnect();

    foreach ($sellers as $i => $seller) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            time_sleep_until($startAt);

            try {
                $result = app(DocumentLookupService::class)->lookup($seller->fresh(), CustomerDocumentType::Ruc, '20131312955');
                $out = ($result->source ?? 'null').':'.($result->customer?->id ?? 'sin-cliente');
            } catch (Throwable $e) {
                $out = 'error: '.$e->getMessage();
            }

            file_put_contents("{$dir}/{$i}", $out);
            pcntl_exec('/bin/true');
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();
    $results = array_map(fn ($file) => file_get_contents($file), glob("{$dir}/*"));
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);

    $sources = collect($results)->map(fn ($r) => explode(':', $r)[0])->sort()->values()->all();

    expect($results)->toHaveCount(2)
        ->and($sources)->toBe(['company', 'service'], 'Resultados: '.implode(', ', $results))
        ->and(DocumentLookupRequest::count())->toBe(1)
        ->and(Customer::withoutTenancy()->where('document_number', '20131312955')->count())->toBe(1);
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

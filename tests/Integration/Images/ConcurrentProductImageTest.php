<?php

use App\Enums\CompanyRole;
use App\Images\ImageStore;
use App\Images\ProductImageService;
use App\Models\Company;
use App\Models\ImageDeletion;
use App\Models\Product;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestImages;

/*
 * Spec 016 · caso límite: varias subidas a la vez al mismo producto dejan una
 * sola foto y ningún objeto huérfano. Solo PostgreSQL y pcntl.
 */

it('subidas simultáneas al mismo producto dejan una imagen y ningún huérfano', function () {
    $disk = Storage::fake('images');
    $company = Company::factory()->withMainEstablishment()->create();
    $admin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();
    $product = Product::factory()->create(['company_id' => $company->id]);
    app(TenantContext::class)->set($company);

    $files = array_map(fn () => TestImages::upload(TestImages::jpeg()), range(1, 5));
    $startAt = microtime(true) + 1.5;
    $children = [];

    DB::disconnect();

    foreach ($files as $file) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge();
            // Subida lenta: todos suben a la vez y compiten por el bloqueo de la fila.
            app()->instance(ImageStore::class, new class extends ImageStore
            {
                public function put(array $objects, string $contentType, ?string $failureMessage = null): void
                {
                    parent::put($objects, $contentType, $failureMessage);
                    usleep(200_000);
                }
            });
            time_sleep_until($startAt);
            app(ProductImageService::class)->replace(Product::find($product->id), $file, $admin);
            pcntl_exec('/bin/true');
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();
    $key = Product::withoutTenancy()->find($product->id)->image_key;

    expect($key)->not->toBeNull()
        ->and($disk->allFiles())->toEqualCanonicalizing(ImageStore::productPaths($key))
        ->and(ImageDeletion::count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'product.image_updated')->count())->toBe(5);
})->skip(
    fn () => DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'),
    'Requiere PostgreSQL y pcntl',
);

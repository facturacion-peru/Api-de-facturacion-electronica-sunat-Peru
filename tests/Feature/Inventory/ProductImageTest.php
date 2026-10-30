<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestImages;

/*
 * Spec 016 · HU-1 y HU-2: foto opcional del producto en el disco de imágenes
 * (S3 en producción, simulado aquí), con claves UUID sin datos.
 */

beforeEach(function () {
    $this->disk = Storage::fake('images');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->product = Product::factory()->create(['company_id' => $this->company->id, 'code' => 'ARZ-001', 'name' => 'Arroz extra 5 kg']);
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken);
    };
    $this->upload = fn (?UploadedFile $file = null, ?Product $product = null) => ($this->as)($this->admin)
        ->postJson('/api/v1/products/'.($product ?? $this->product)->id.'/image', ['image' => $file ?? TestImages::upload(TestImages::jpeg(1200, 800))]);
});

/** @return list<string> */
function imageObjects(string $key): array
{
    return ["{$key}.webp", "{$key}-thumb.webp"];
}

it('HU-1.1 el administrador sube la foto y queda en el disco en dos tamaños', function () {
    $response = ($this->upload)()->assertOk();

    $key = $this->product->fresh()->image_key;
    expect($key)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($response->json('data.image.url'))->toBe($this->disk->url("{$key}.webp"))
        ->and($response->json('data.image.thumb_url'))->toBe($this->disk->url("{$key}-thumb.webp"))
        ->and(AuditLog::withoutTenancy()->where('action', 'product.image_updated')->count())->toBe(1);

    foreach (imageObjects($key) as $path) {
        $this->disk->assertExists($path);
        expect(getimagesizefromstring($this->disk->get($path))[2])->toBe(IMAGETYPE_WEBP);
    }
    expect(getimagesizefromstring($this->disk->get("{$key}.webp"))[0])->toBe(800);
});

it('HU-1.2 cambiar la foto borra la anterior', function () {
    ($this->upload)()->assertOk();
    $first = $this->product->fresh()->image_key;

    ($this->upload)(TestImages::upload(TestImages::png(), 'nueva.png'))->assertOk();
    $second = $this->product->fresh()->image_key;

    expect($second)->not->toBe($first)
        ->and(AuditLog::withoutTenancy()->where('action', 'product.image_updated')->count())->toBe(2)
        ->and($this->disk->allFiles())->toEqualCanonicalizing(imageObjects($second));
});

it('HU-1.3 quitar la foto borra los objetos y deja image en null', function () {
    ($this->upload)()->assertOk();

    ($this->as)($this->admin)->deleteJson("/api/v1/products/{$this->product->id}/image")
        ->assertOk()
        ->assertJsonPath('data.image', null);

    expect($this->product->fresh()->image_key)->toBeNull()
        ->and($this->disk->allFiles())->toBe([])
        ->and(AuditLog::withoutTenancy()->where('action', 'product.image_removed')->count())->toBe(1);
});

it('quitar sin foto responde 200 y no registra nada', function () {
    ($this->as)($this->admin)->deleteJson("/api/v1/products/{$this->product->id}/image")
        ->assertOk()
        ->assertJsonPath('data.image', null);

    expect(AuditLog::withoutTenancy()->where('action', 'product.image_removed')->count())->toBe(0);
});

it('acepta JPEG, PNG y WebP', function (string $bytes, string $name) {
    ($this->upload)(TestImages::upload($bytes, $name))->assertOk();
})->with([
    'JPEG' => [fn () => TestImages::jpeg(), 'foto.jpg'],
    'PNG' => [fn () => TestImages::png(), 'foto.png'],
    'WebP' => [fn () => TestImages::webp(), 'foto.webp'],
]);

it('rechaza formatos, tamaños, dimensiones o contenidos inválidos sin tocar el producto', function (Closure $file) {
    ($this->upload)($file())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    expect($this->product->fresh()->image_key)->toBeNull()
        ->and($this->disk->allFiles())->toBe([]);
})->with([
    'GIF' => [fn () => TestImages::upload(TestImages::gif(), 'foto.gif')],
    'PDF' => [fn () => UploadedFile::fake()->create('foto.pdf', 100, 'application/pdf')],
    'más de 2 MB' => [fn () => UploadedFile::fake()->image('foto.jpg', 100, 100)->size(2049)],
    'más de 4000 px' => [fn () => TestImages::upload(TestImages::png(4001, 10), 'foto.png')],
    'bomba de descompresión' => [fn () => TestImages::upload(TestImages::pngDeclaring(20000, 20000), 'foto.png')],
    'texto con nombre de foto' => [fn () => TestImages::upload(TestImages::fake(), 'foto.jpg')],
]);

it('exige el archivo', function () {
    ($this->as)($this->admin)->postJson("/api/v1/products/{$this->product->id}/image", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);
});

it('el vendedor no cambia ni quita fotos', function () {
    ($this->as)($this->seller)->postJson("/api/v1/products/{$this->product->id}/image", ['image' => TestImages::upload(TestImages::jpeg())])->assertForbidden();
    ($this->as)($this->seller)->deleteJson("/api/v1/products/{$this->product->id}/image")->assertForbidden();

    expect($this->disk->allFiles())->toBe([]);
});

it('un producto de otra empresa da 404', function () {
    $other = Product::factory()->create(['company_id' => Company::factory()->withMainEstablishment()->create()->id]);
    app(\App\Tenancy\TenantContext::class)->clear();

    ($this->upload)(null, $other)->assertNotFound();
    ($this->as)($this->admin)->deleteJson("/api/v1/products/{$other->id}/image")->assertNotFound();

    expect($this->disk->allFiles())->toBe([]);
});

it('HU-2 la lista y la ficha muestran la foto, y null sin foto', function () {
    $without = Product::factory()->create(['company_id' => $this->company->id]);
    ($this->upload)()->assertOk();
    $key = $this->product->fresh()->image_key;

    $list = collect(($this->as)($this->seller)->getJson('/api/v1/products')->assertOk()->json('data'))->keyBy('id');

    expect($list[$this->product->id]['image'])->toBe(['url' => $this->disk->url("{$key}.webp"), 'thumb_url' => $this->disk->url("{$key}-thumb.webp")])
        ->and($list[$without->id]['image'])->toBeNull();

    ($this->as)($this->seller)->getJson("/api/v1/products/{$this->product->id}")
        ->assertOk()
        ->assertJsonPath('data.image.thumb_url', $this->disk->url("{$key}-thumb.webp"));
});

it('las direcciones no llevan RUC, id, código ni nombre (RF-005)', function () {
    $image = ($this->upload)()->assertOk()->json('data.image');

    foreach ($image as $url) {
        $file = basename(parse_url($url, PHP_URL_PATH));

        expect($file)->toMatch('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}(-thumb)?\.webp$/')
            ->and($url)->not->toContain($this->company->ruc)
            ->not->toContain('ARZ')
            ->not->toContain('Arroz')
            ->not->toContain("/{$this->product->id}/")
            ->not->toContain("/{$this->company->id}/");
    }
});

it('desactivar el producto conserva su foto (A-82)', function () {
    ($this->upload)()->assertOk();
    $key = $this->product->fresh()->image_key;

    ($this->as)($this->admin)->patchJson("/api/v1/products/{$this->product->id}", ['active' => false])->assertOk();

    expect($this->product->fresh()->image_key)->toBe($key)
        ->and($this->disk->allFiles())->toEqualCanonicalizing(imageObjects($key));
});

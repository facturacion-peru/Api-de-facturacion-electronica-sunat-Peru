<?php

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ImageDeletion;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FailingDisks;
use Tests\Support\TestImages;

/*
 * Spec 016 · RF-010: si S3 falla, subir responde 503 sin cambiar nada, las
 * listas siguen funcionando y ningún objeto queda huérfano sin registro.
 */

beforeEach(function () {
    $this->disk = Storage::fake('images');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    $this->asAdmin = function () {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    };
    $this->upload = fn () => ($this->asAdmin)()
        ->postJson("/api/v1/products/{$this->product->id}/image", ['image' => TestImages::upload(TestImages::jpeg())]);
});

it('con S3 caído, subir da 503 y el producto conserva su foto', function () {
    ($this->upload)()->assertOk();
    $key = $this->product->fresh()->image_key;
    Storage::set('images', FailingDisks::unreachable());

    ($this->upload)()
        ->assertStatus(503)
        ->assertExactJson(['success' => false, 'message' => 'No se pudo guardar la foto. Inténtalo de nuevo.']);

    expect($this->product->fresh()->image_key)->toBe($key)
        ->and(AuditLog::withoutTenancy()->where('action', 'product.image_updated')->count())->toBe(1);
});

it('con S3 caído, la lista y la ficha siguen respondiendo con sus direcciones', function () {
    ($this->upload)()->assertOk();
    Storage::set('images', FailingDisks::unreachable());

    ($this->asAdmin)()->getJson('/api/v1/products')->assertOk()
        ->assertJsonPath('data.0.image.thumb_url', fn (string $url) => str_ends_with($url, '-thumb.webp'));
    ($this->asAdmin)()->getJson("/api/v1/products/{$this->product->id}")->assertOk();
});

it('con S3 caído, quitar la foto responde 200 y deja los objetos pendientes de borrar', function () {
    ($this->upload)()->assertOk();
    $key = $this->product->fresh()->image_key;
    Storage::set('images', FailingDisks::unreachable());

    ($this->asAdmin)()->deleteJson("/api/v1/products/{$this->product->id}/image")
        ->assertOk()
        ->assertJsonPath('data.image', null);

    expect(ImageDeletion::pluck('path')->all())->toEqualCanonicalizing(["{$key}.webp", "{$key}-thumb.webp"]);
});

it('si no se puede borrar la foto anterior, la nueva queda y la anterior va a image_deletions', function () {
    ($this->upload)()->assertOk();
    $first = $this->product->fresh()->image_key;
    Storage::set('images', FailingDisks::withoutDeletes(Storage::disk('images')->path('')));

    ($this->upload)()->assertOk();
    $second = $this->product->fresh()->image_key;

    expect($second)->not->toBe($first)
        ->and(ImageDeletion::pluck('path')->all())->toEqualCanonicalizing(["{$first}.webp", "{$first}-thumb.webp"]);
    $this->disk->assertExists("{$second}.webp");
});

it('si falla la transacción después de subir, los objetos nuevos se borran', function () {
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Fallo simulado'));
    app()->instance(AuditLogger::class, $audit);

    ($this->upload)()->assertStatus(500);

    expect($this->product->fresh()->image_key)->toBeNull()
        ->and($this->disk->allFiles())->toBe([])
        ->and(ImageDeletion::count())->toBe(0);
});

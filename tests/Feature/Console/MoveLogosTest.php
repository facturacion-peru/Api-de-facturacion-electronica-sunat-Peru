<?php

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FailingDisks;
use Tests\Support\TestImages;

/*
 * Spec 016 · HU-4: `images:move-logos` lleva los logos antiguos del disco
 * `public` al disco de imágenes. Lo corre cada despliegue: es idempotente.
 */

beforeEach(function () {
    $this->images = Storage::fake('images');
    $this->public = Storage::fake('public');
});

function legacyLogo(string $bytes): Company
{
    $company = Company::factory()->create();
    $path = "companies/{$company->id}/logo.png";
    Storage::disk('public')->put($path, $bytes);
    $company->update(['logo_path' => $path]);

    return $company;
}

it('mueve los logos antiguos como PNG con clave UUID y borra el archivo local', function () {
    $company = legacyLogo(TestImages::png(400, 200));
    $old = $company->logo_path;

    $this->artisan('images:move-logos')->assertSuccessful()->expectsOutputToContain('Logos movidos: 1 de 1.');

    $path = $company->fresh()->logo_path;
    expect($path)->toMatch('/^[0-9a-f-]{36}\.png$/')
        ->and(TestImages::colorAt($this->images->get($path), 390, 190)[3])->toBe(127);
    $this->public->assertMissing($old);
});

it('es idempotente: los logos ya movidos y las empresas sin logo no se tocan', function () {
    $moved = Company::factory()->create(['logo_path' => '0b6f3c2e-6d1a-4c3e-9f7a-2b8d5e4c1a90.png']);
    Company::factory()->create(['logo_path' => null]);

    $this->artisan('images:move-logos')->assertSuccessful()->expectsOutputToContain('Logos movidos: 0 de 0.');

    expect($moved->fresh()->logo_path)->toBe('0b6f3c2e-6d1a-4c3e-9f7a-2b8d5e4c1a90.png');
});

it('si uno falla sigue con los demás y termina con error', function () {
    $broken = legacyLogo(TestImages::fake());
    $good = legacyLogo(TestImages::jpeg());

    $this->artisan('images:move-logos')->assertFailed()->expectsOutputToContain('Logos movidos: 1 de 2.');

    expect($broken->fresh()->logo_path)->toBe("companies/{$broken->id}/logo.png")
        ->and($good->fresh()->logo_path)->not->toContain('/');
    $this->public->assertExists("companies/{$broken->id}/logo.png");
});

it('con S3 caído no cambia nada y termina con error', function () {
    $company = legacyLogo(TestImages::png());
    Storage::set('images', FailingDisks::unreachable());

    $this->artisan('images:move-logos')->assertFailed();

    expect($company->fresh()->logo_path)->toBe("companies/{$company->id}/logo.png");
    $this->public->assertExists("companies/{$company->id}/logo.png");
});

it('si el archivo antiguo ya no existe, quita la ruta rota', function () {
    $company = Company::factory()->create();
    $company->update(['logo_path' => "companies/{$company->id}/perdido.png"]);

    $this->artisan('images:move-logos')->assertSuccessful();

    expect($company->fresh()->logo_path)->toBeNull();
});

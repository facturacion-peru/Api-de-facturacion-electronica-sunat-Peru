<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FailingDisks;
use Tests\Support\TestImages;

/*
 * Spec 016 · HU-4: el logo pasa al disco de imágenes como PNG con alfa, con
 * clave UUID. Las rutas antiguas del disco `public` se siguen leyendo.
 */

beforeEach(function () {
    $this->images = Storage::fake('images');
    $this->public = Storage::fake('public');
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->upload = function (UploadedFile $file) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)->postJson('/api/v1/company/logo', ['logo' => $file]);
    };
});

it('HU-4.1 guarda el logo como PNG con transparencia en el disco de imágenes', function () {
    $response = ($this->upload)(TestImages::upload(TestImages::png(400, 200), 'logo.png'))->assertOk();

    $path = $this->company->fresh()->logo_path;
    expect($path)->toMatch('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\.png$/')
        ->and($response->json('data.logo_url'))->toBe($this->images->url($path))
        ->and(AuditLog::withoutTenancy()->where('action', 'company.logo_updated')->count())->toBe(1)
        ->and($this->public->allFiles())->toBe([]);

    $stored = $this->images->get($path);
    expect(getimagesizefromstring($stored)[2])->toBe(IMAGETYPE_PNG)
        ->and(TestImages::colorAt($stored, 390, 190)[3])->toBe(127);
});

it('HU-4.5 cambiar el logo borra el anterior', function () {
    ($this->upload)(TestImages::upload(TestImages::png(), 'logo.png'))->assertOk();
    $first = $this->company->fresh()->logo_path;

    ($this->upload)(TestImages::upload(TestImages::jpeg(), 'nuevo.jpg'))->assertOk();
    $second = $this->company->fresh()->logo_path;

    expect($second)->not->toBe($first)
        ->and($this->images->allFiles())->toBe([$second]);
});

it('cambiar un logo antiguo del disco public lo borra de ahí', function () {
    $legacy = "companies/{$this->company->id}/viejo.png";
    $this->public->put($legacy, TestImages::png());
    $this->company->update(['logo_path' => $legacy]);

    ($this->upload)(TestImages::upload(TestImages::png(), 'logo.png'))->assertOk();

    $this->public->assertMissing($legacy);
});

it('logo_url de una ruta antigua sigue saliendo del disco public', function () {
    $legacy = "companies/{$this->company->id}/viejo.png";
    $this->company->update(['logo_path' => $legacy]);
    app('auth')->forgetGuards();

    $this->withToken($this->admin->createToken('t')->plainTextToken)->getJson('/api/v1/company')
        ->assertOk()
        ->assertJsonPath('data.logo_url', $this->public->url($legacy));
});

it('rechaza logos de otro formato, de más de 1 MB o de más de 4000 px', function (Closure $file) {
    ($this->upload)($file())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);

    expect($this->images->allFiles())->toBe([])
        ->and($this->company->fresh()->logo_path)->toBeNull();
})->with([
    'PDF' => [fn () => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')],
    'SVG' => [fn () => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')],
    'WebP' => [fn () => TestImages::upload(TestImages::webp(), 'logo.webp')],
    'PNG de 2 MB' => [fn () => UploadedFile::fake()->image('logo.png', 100, 100)->size(2048)],
    'más de 4000 px' => [fn () => TestImages::upload(TestImages::png(4001, 10), 'logo.png')],
    'texto con nombre de PNG' => [fn () => TestImages::upload(TestImages::fake(), 'logo.png')],
]);

it('con S3 caído responde 503 y conserva el logo', function () {
    ($this->upload)(TestImages::upload(TestImages::png(), 'logo.png'))->assertOk();
    $path = $this->company->fresh()->logo_path;
    Storage::set('images', FailingDisks::unreachable());

    ($this->upload)(TestImages::upload(TestImages::png(), 'logo.png'))
        ->assertStatus(503)
        ->assertJsonPath('message', 'No se pudo guardar el logo. Inténtalo de nuevo.');

    expect($this->company->fresh()->logo_path)->toBe($path);
});

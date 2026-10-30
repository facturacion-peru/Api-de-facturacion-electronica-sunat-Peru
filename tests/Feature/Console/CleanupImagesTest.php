<?php

use App\Models\ImageDeletion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FailingDisks;

/*
 * Spec 016 · A-82: `images:cleanup` reintenta los borrados pendientes y se
 * rinde a los 10 intentos con un aviso.
 */

it('borra los objetos pendientes y sus registros', function () {
    $disk = Storage::fake('images');
    $disk->put('a.webp', 'x');
    $disk->put('a-thumb.webp', 'x');
    ImageDeletion::enqueue('a.webp', 'a-thumb.webp', 'ya-no-existe.png');

    $this->artisan('images:cleanup')->assertSuccessful()->expectsOutputToContain('Imágenes borradas: 3 de 3.');

    expect($disk->allFiles())->toBe([])
        ->and(ImageDeletion::count())->toBe(0);
});

it('si sigue fallando suma un intento y guarda el error', function () {
    Storage::set('images', FailingDisks::withoutDeletes(Storage::fake('images')->path('')));
    ImageDeletion::enqueue('a.webp');

    $this->artisan('images:cleanup')->assertSuccessful();

    expect(ImageDeletion::first())
        ->attempts->toBe(1)
        ->last_error->toContain('Borrado simulado');
});

it('al décimo intento avisa y deja de intentarlo', function () {
    Log::spy();
    Storage::set('images', FailingDisks::withoutDeletes(Storage::fake('images')->path('')));
    ImageDeletion::create(['path' => 'a.webp', 'attempts' => ImageDeletion::MAX_ATTEMPTS - 1]);

    $this->artisan('images:cleanup')->assertSuccessful();
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context['path'] === 'a.webp');

    $this->artisan('images:cleanup')->assertSuccessful()->expectsOutputToContain('de 0.');
    expect(ImageDeletion::first()->attempts)->toBe(ImageDeletion::MAX_ATTEMPTS);
});

it('con S3 caído, cada objeto suma un intento', function () {
    Storage::set('images', FailingDisks::unreachable());
    ImageDeletion::enqueue('a.webp', 'b.webp');

    $this->artisan('images:cleanup')->assertSuccessful();

    expect(ImageDeletion::pluck('attempts')->all())->toBe([1, 1]);
});

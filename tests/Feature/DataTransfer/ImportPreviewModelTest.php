<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\ImportPreview;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/*
 * Spec 014 · T015: las vistas previas caducan a los 30 minutos y se borran
 * un día después, sin contexto de empresa (scheduler).
 */

function previewFor(Company $company, Carbon $expiresAt): ImportPreview
{
    $user = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();

    return app(TenantContext::class)->run($company, fn () => ImportPreview::create([
        'user_id' => $user->id, 'kind' => 'products', 'mode' => 'create', 'rows' => [], 'summary' => [],
        'errors' => [], 'warnings' => [], 'changes' => [], 'expires_at' => $expiresAt,
    ]));
}

it('model:prune borra las vistas previas de todas las empresas caducadas hace más de un día', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');
    [$a, $b] = Company::factory()->withMainEstablishment()->count(2)->create();
    $old = previewFor($a, now()->subDays(2));
    $oldB = previewFor($b, now()->subHours(25));
    $recent = previewFor($a, now()->subHours(2));
    $alive = previewFor($b, now()->addMinutes(10));
    app(TenantContext::class)->clear();

    $this->artisan('model:prune', ['--model' => [ImportPreview::class]])->assertSuccessful();

    expect(ImportPreview::withoutTenancy()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$recent->id, $alive->id])->sort()->values()->all());
    Carbon::setTestNow();
});

it('caduca a los 30 minutos y solo se confirma sin errores ni confirmación previa', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $preview = previewFor($company, now()->addMinutes(ImportPreview::TTL_MINUTES));

    expect($preview->canConfirm())->toBeTrue()
        ->and(tap(clone $preview, fn ($p) => $p->errors = [['row' => 2]])->canConfirm())->toBeFalse()
        ->and(tap(clone $preview, fn ($p) => $p->confirmed_at = now())->canConfirm())->toBeFalse();

    $this->travel(31)->minutes();
    expect($preview->isExpired())->toBeTrue()->and($preview->canConfirm())->toBeFalse();
});

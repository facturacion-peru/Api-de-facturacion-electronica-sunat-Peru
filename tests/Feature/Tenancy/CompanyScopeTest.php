<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;

/*
 * T015 · El scope de empresa falla cerrado (principio VIII, RF-030/031).
 */

beforeEach(function () {
    $this->companyA = Company::factory()->withMainEstablishment()->create();
    $this->companyB = Company::factory()->withMainEstablishment()->create();
    $this->tenant = app(TenantContext::class);
});

it('lanza una excepción al leer sin contexto de empresa', function () {
    Establishment::query()->get();
})->throws(TenantContextMissing::class);

it('lanza una excepción al buscar por id sin contexto de empresa', function () {
    Establishment::find(1);
})->throws(TenantContextMissing::class);

it('con contexto solo lista los registros de esa empresa', function () {
    $this->tenant->set($this->companyA);

    $ids = Establishment::pluck('company_id')->unique()->all();

    expect($ids)->toBe([$this->companyA->id]);
});

it('con contexto no encuentra registros de otra empresa por id', function () {
    $ajeno = Establishment::withoutTenancy()->where('company_id', $this->companyB->id)->first();

    $this->tenant->set($this->companyA);

    expect(Establishment::find($ajeno->id))->toBeNull();
});

it('al crear con contexto rellena company_id', function () {
    $this->tenant->set($this->companyA);

    $local = Establishment::factory()->make(['company_id' => null]);
    $local->save();

    expect($local->company_id)->toBe($this->companyA->id);
});

it('rechaza crear un registro para otra empresa', function () {
    $this->tenant->set($this->companyA);

    Establishment::factory()->create(['company_id' => $this->companyB->id]);
})->throws(TenantMismatch::class);

it('rechaza mover un registro a otra empresa', function () {
    $this->tenant->set($this->companyA);
    $local = Establishment::first();

    $local->update(['company_id' => $this->companyB->id]);
})->throws(TenantMismatch::class);

it('rechaza crear sin contexto ni company_id explícito', function () {
    Establishment::factory()->make(['company_id' => null])->save();
})->throws(TenantContextMissing::class);

it('permite crear sin contexto con company_id explícito (consola y plataforma)', function () {
    $local = Establishment::factory()->create(['company_id' => $this->companyA->id]);

    expect($local->company_id)->toBe($this->companyA->id);
});

it('withoutTenancy es la única forma de leer todas las empresas', function () {
    expect(Establishment::withoutTenancy()->count())->toBe(2);
});

it('run fija el contexto temporalmente y restaura el anterior', function () {
    $this->tenant->set($this->companyA);

    $dentro = $this->tenant->run($this->companyB, fn () => Establishment::pluck('company_id')->all());

    expect($dentro)->toBe([$this->companyB->id])
        ->and($this->tenant->id())->toBe($this->companyA->id);
});

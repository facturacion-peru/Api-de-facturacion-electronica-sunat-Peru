<?php

use App\Enums\CompanyRole;
use App\Establishments\EstablishmentAccess;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/*
 * T004 · A-87 (spec 017): en qué locales trabaja y qué locales ve cada
 * usuario. El administrador de empresa, en todos; el vendedor, en los suyos.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $this->second = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0001']);
    $this->closed = Establishment::factory()->create(['company_id' => $this->company->id, 'code' => '0002', 'active' => false]);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->seller = User::factory()->forCompany($this->company)->create();
    $this->seller->establishments()->attach([
        $this->second->id => ['company_id' => $this->company->id],
        $this->closed->id => ['company_id' => $this->company->id],
    ]);
    app(TenantContext::class)->set($this->company);
    $this->access = app(EstablishmentAccess::class);
});

/** Mensaje de validación de un campo, o null si no se lanzó. */
function establishmentError(callable $call, string $field = 'establishment_id'): ?string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors()[$field][0] ?? null;
    }

    return null;
}

it('el administrador trabaja en todos los locales activos y el vendedor solo en sus asignados activos', function () {
    expect($this->access->allowed($this->admin)->pluck('id')->all())->toBe([$this->main->id, $this->second->id])
        ->and($this->access->allowed($this->seller)->pluck('id')->all())->toBe([$this->second->id]);
});

it('las asignaciones de un administrador no lo limitan', function () {
    $this->admin->establishments()->attach($this->second->id, ['company_id' => $this->company->id]);

    expect($this->access->allowed($this->admin->fresh())->pluck('id')->all())->toBe([$this->main->id, $this->second->id]);
});

it('sin local en la petición usa el único permitido', function () {
    expect($this->access->resolve($this->seller, null)->id)->toBe($this->second->id);
});

it('sin local en la petición y con varios permitidos pide elegir', function () {
    expect(establishmentError(fn () => $this->access->resolve($this->admin, null)))->toBe('Elige el local.');
});

it('rechaza un local no asignado, inactivo, de otra empresa o inexistente con el mismo mensaje', function () {
    app(TenantContext::class)->clear();
    $other = Company::factory()->withMainEstablishment()->create();
    $foreign = Establishment::withoutTenancy()->where('company_id', $other->id)->value('id');
    app(TenantContext::class)->set($this->company);

    foreach ([$this->main->id, $this->closed->id, $foreign, 999999] as $id) {
        expect(establishmentError(fn () => $this->access->resolve($this->seller, $id)))->toBe('No puedes trabajar en ese local.');
    }

    expect(establishmentError(fn () => $this->access->resolve($this->admin, $this->closed->id)))->toBe('No puedes trabajar en ese local.');
});

it('informa el error en el campo que se indique', function () {
    expect(establishmentError(fn () => $this->access->resolve($this->seller, $this->main->id, 'from_establishment_id'), 'from_establishment_id'))
        ->toBe('No puedes trabajar en ese local.');
});

it('el vendedor sin locales activos no puede trabajar', function () {
    $alone = User::factory()->forCompany($this->company)->create();

    expect($this->access->allowed($alone))->toBeEmpty()
        ->and(establishmentError(fn () => $this->access->resolve($alone, null)))->toBe('No tienes un local asignado. Pide al administrador que te asigne uno.');
});

it('«Todos» son los locales que puede leer, incluidos los inactivos', function () {
    expect($this->access->filter($this->admin, null))->toBe([$this->main->id, $this->second->id, $this->closed->id])
        ->and($this->access->filter($this->seller, null))->toBe([$this->second->id, $this->closed->id])
        ->and($this->access->filter($this->seller, $this->closed->id))->toBe([$this->closed->id])
        ->and(establishmentError(fn () => $this->access->filter($this->seller, $this->main->id)))->toBe('No puedes ver ese local.');
});

it('visibleTo limita tickets y lotes a los locales que el usuario puede leer', function () {
    $mainTicket = Ticket::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->main->id]);
    $secondTicket = Ticket::factory()->create(['company_id' => $this->company->id, 'establishment_id' => $this->second->id]);
    $lot = ProductLot::factory()->create(['establishment_id' => $this->second->id, 'company_id' => $this->company->id,
        'product_id' => Product::factory()->create(['company_id' => $this->company->id])->id]);

    expect(Ticket::visibleTo($this->seller)->pluck('id')->all())->toBe([$secondTicket->id])
        ->and(Ticket::visibleTo($this->admin)->pluck('id')->sort()->values()->all())->toBe([$mainTicket->id, $secondTicket->id])
        ->and(ProductLot::visibleTo($this->seller)->pluck('id')->all())->toBe([$lot->id]);
});

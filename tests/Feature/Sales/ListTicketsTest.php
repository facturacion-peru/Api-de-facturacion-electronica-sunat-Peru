<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;

/*
 * T050 · HU-4 Consultar ventas (RF-012).
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->luis = User::factory()->forCompany($this->company, CompanyRole::Seller)->create(['name' => 'Luis']);
    $this->rosa = User::factory()->forCompany($this->company, CompanyRole::Seller)->create(['name' => 'Rosa']);

    $make = fn (User $seller, string $method, string $total, string $date, bool $voided = false) => Ticket::factory()
        ->when($voided, fn ($f) => $f->voided())
        ->create(['company_id' => $this->company->id, 'seller_id' => $seller->id, 'payment_method' => $method, 'total' => $total, 'issued_at' => $date.' 10:00:00']);

    $make($this->luis, 'cash', '10.00', '2026-09-27');
    $make($this->luis, 'yape_plin', '25.50', '2026-09-28');
    $make($this->rosa, 'cash', '4.50', '2026-09-28');
    $make($this->rosa, 'card', '100.00', '2026-09-28', voided: true);

    $this->list = function (User $as, array $query = []) {
        app('auth')->forgetGuards();

        return $this->withToken($as->createToken('t')->plainTextToken)->getJson('/api/v1/tickets?'.http_build_query($query));
    };
});

it('HU-4.1 filtra por fechas y totaliza por medio de pago sin contar anulados', function () {
    $response = ($this->list)($this->admin, ['from' => '2026-09-28', 'to' => '2026-09-28'])->assertOk();

    expect($response->json('meta.total'))->toBe(3)
        ->and($response->json('totals'))->toBe([
            'count' => 2,
            'total' => '30.00',
            'by_payment_method' => ['cash' => '4.50', 'card' => '0.00', 'yape_plin' => '25.50', 'transfer' => '0.00'],
        ]);
});

it('filtra por estado, medio de pago y vendedor', function () {
    $count = fn (array $query) => ($this->list)($this->admin, $query)->json('meta.total');

    expect($count(['status' => 'voided']))->toBe(1)
        ->and($count(['payment_method' => 'cash']))->toBe(2)
        ->and($count(['seller_id' => $this->rosa->id]))->toBe(2);
});

it('lista del más reciente al más antiguo, sin líneas y paginado', function () {
    $response = ($this->list)($this->admin)->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('lines')
        ->and($response->json('meta'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page'])
        ->and(substr($response->json('data.3.issued_at'), 0, 10))->toBe('2026-09-27');
});

it('HU-4.2 el vendedor solo ve sus tickets, aunque filtre por otro vendedor', function () {
    $response = ($this->list)($this->luis, ['seller_id' => $this->rosa->id])->assertOk();

    expect(collect($response->json('data'))->pluck('seller.name')->unique()->all())->toBe(['Luis'])
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('totals.total'))->toBe('35.50');
});

it('HU-4.2 el vendedor no ve el detalle de un ticket ajeno', function () {
    $ajeno = Ticket::withoutTenancy()->where('seller_id', $this->rosa->id)->first();

    app('auth')->forgetGuards();
    $this->withToken($this->luis->createToken('t')->plainTextToken)
        ->getJson("/api/v1/tickets/{$ajeno->id}")->assertNotFound();

    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)
        ->getJson("/api/v1/tickets/{$ajeno->id}")->assertOk();
});

it('valida los filtros', function () {
    ($this->list)($this->admin, ['from' => 'ayer', 'status' => 'raro', 'payment_method' => 'cheque'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from', 'status', 'payment_method']);
});

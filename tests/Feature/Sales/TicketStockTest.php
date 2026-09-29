<?php

use App\Enums\MovementType;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\TicketSequence;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * T022 · HU-1.3 y RF-003: si una línea no tiene stock, la venta completa se
 * rechaza y no queda nada: ni ticket, ni líneas, ni movimientos, ni número.
 */

it('rechaza toda la venta si una línea no tiene stock y no consume el número', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $seller = User::factory()->forCompany($company)->create();
    $con = Product::factory()->create(['company_id' => $company->id, 'name' => 'Con stock']);
    ProductLot::factory()->for($con)->quantity('10')->create();
    $sin = Product::factory()->create(['company_id' => $company->id, 'name' => 'Poco stock']);
    ProductLot::factory()->for($sin)->quantity('2')->create();
    $token = $seller->createToken('t')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(),
        'payment_method' => 'cash',
        'lines' => [
            ['product_id' => $con->id, 'quantity' => '3'],
            ['product_id' => $sin->id, 'quantity' => '5'],
        ],
    ])->assertUnprocessable();

    expect($response->json('errors.quantity.0'))->toBe('Solo hay 2.000 disponible de Poco stock.')
        ->and($response->json('meta'))->toBe(['available' => '2.000', 'product_id' => $sin->id])
        ->and(Ticket::withoutTenancy()->count())->toBe(0)
        ->and(TicketLine::withoutTenancy()->count())->toBe(0)
        ->and(InventoryMovement::withoutTenancy()->where('type', MovementType::Sale)->count())->toBe(0)
        ->and(ProductLot::withoutTenancy()->where('product_id', $con->id)->value('remaining_quantity'))->toBe('10.000')
        ->and((int) TicketSequence::withoutTenancy()->where('company_id', $company->id)->value('last_number'))->toBe(0);

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/tickets', [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash',
        'lines' => [['product_id' => $con->id, 'quantity' => '1']],
    ])->assertCreated()->assertJsonPath('data.display_number', 'T-000001');
});

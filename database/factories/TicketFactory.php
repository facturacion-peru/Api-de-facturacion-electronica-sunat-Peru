<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Tickets sin líneas para pruebas de listado y aislamiento. Los tickets con
 * líneas y stock se crean con TicketService, como en producción.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    private static int $number = 0;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'number' => ++self::$number,
            'status' => TicketStatus::Issued,
            'seller_id' => null,
            'payment_method' => PaymentMethod::Cash,
            'subtotal' => '10.00',
            'discount_total' => '0.00',
            'total' => '10.00',
            'idempotency_key' => (string) Str::uuid(),
            'issued_at' => now(),
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => ['status' => TicketStatus::Voided, 'voided_at' => now(), 'void_reason' => 'Error']);
    }
}

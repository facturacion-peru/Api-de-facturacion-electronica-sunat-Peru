<?php

use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;

/*
 * T012 · Catálogos de ventas (spec 003).
 */

it('define los medios de pago del MVP con etiqueta en español', function () {
    expect(array_column(PaymentMethod::cases(), 'value'))->toBe(['cash', 'card', 'yape_plin', 'transfer'])
        ->and(PaymentMethod::YapePlin->label())->toBe('Yape / Plin');
});

it('define los estados del ticket', function () {
    expect(array_column(TicketStatus::cases(), 'value'))->toBe(['issued', 'voided'])
        ->and(TicketStatus::Voided->label())->toBe('Anulado');
});

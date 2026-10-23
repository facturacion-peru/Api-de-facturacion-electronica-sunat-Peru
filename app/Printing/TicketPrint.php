<?php

namespace App\Printing;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Printing\Escpos\Align;
use App\Printing\Escpos\EscposBuilder;

/**
 * Ticket interno para la impresora térmica (spec 015): el mismo contenido y
 * orden que su PDF de 80 mm (`pdf/ticket.blade.php`), con la leyenda de
 * documento interno arriba y abajo (A-17). Espera `lines`, `seller` y `company`.
 */
final class TicketPrint
{
    public function render(Ticket $ticket, PrintOptions $options): string
    {
        $money = fn (string $amount) => 'S/ '.number_format((float) $amount, 2, '.', ',');
        $printer = new EscposBuilder($options);

        if ($ticket->status === TicketStatus::Voided) {
            $printer->text('ANULADO', Align::Center, bold: true, big: true);
        }

        $printer
            ->text($ticket->company->nombre_comercial ?: $ticket->company->razon_social, Align::Center, bold: true)
            ->text('RUC '.$ticket->company->ruc, Align::Center)
            ->text('TICKET '.$ticket->display_number, Align::Center, bold: true)
            ->text($ticket->issued_at->timezone(config('app.timezone'))->format('d/m/Y H:i'), Align::Center)
            ->rule()
            ->text(Ticket::LEGAL_NOTICE, Align::Center, bold: true)
            ->rule()
            ->pair('Cliente', $ticket->customer_name ?? 'Cliente varios');

        if ($ticket->customer_document) {
            $printer->pair('Doc.', $ticket->customer_document);
        }
        if ($ticket->seller) {
            $printer->pair('Atendió', $ticket->seller->name);
        }

        $printer->rule();
        foreach ($ticket->lines as $line) {
            $printer
                ->text($line->product_name)
                ->row(rtrim(rtrim($line->quantity, '0'), '.').' × '.$money($line->unit_price), $money($line->gross_amount));
            if ((float) $line->discount > 0) {
                $printer->row('Descuento', '−'.$money($line->discount));
            }
        }

        $printer->rule();
        if ((float) $ticket->discount_total > 0) {
            $printer->row('Descuentos', '−'.$money($ticket->discount_total));
        }

        return $printer
            ->row('TOTAL', $money($ticket->total), bold: true, big: true)
            ->row('Pago', $ticket->payment_method->label())
            ->feed()
            ->text(Ticket::LEGAL_NOTICE, Align::Center)
            ->feedAndCut()
            ->bytes();
    }
}

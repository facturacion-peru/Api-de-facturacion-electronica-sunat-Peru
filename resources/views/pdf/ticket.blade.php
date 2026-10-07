{{-- Ticket interno en 80 mm (spec 013): el mismo contenido que TicketReceipt del frontend. --}}
@php($money = fn ($amount) => 'S/ '.number_format((float) $amount, 2, '.', ','))
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 8pt; }
    body { font-family: Courier, monospace; font-size: 8pt; color: #000; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .notice { border-top: 1pt dashed #000; border-bottom: 1pt dashed #000; padding: 2pt 0; margin: 4pt 0; text-align: center; font-weight: bold; }
    .voided { border: 1.5pt solid #000; text-align: center; font-weight: bold; padding: 2pt; margin-bottom: 4pt; }
    .rule { border-top: 1pt dashed #000; margin: 4pt 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 0.5pt 0; vertical-align: top; }
    .right { text-align: right; }
</style>
</head>
<body>
    @if ($ticket->status === \App\Enums\TicketStatus::Voided)
        <div class="voided">ANULADO</div>
    @endif

    <div class="center">
        <div class="bold">{{ $companyName }}</div>
        <div>RUC {{ $ticket->company->ruc }}</div>
        <div class="bold" style="margin-top: 4pt;">TICKET {{ $ticket->display_number }}</div>
        <div>{{ $ticket->issued_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
    </div>

    <div class="notice">{{ \App\Models\Ticket::LEGAL_NOTICE }}</div>

    <table>
        <tr><td>Cliente</td><td class="right">{{ $ticket->customer_name ?? 'Cliente varios' }}</td></tr>
        @if ($ticket->customer_document)
            <tr><td>Doc.</td><td class="right">{{ $ticket->customer_document }}</td></tr>
        @endif
        @if ($ticket->seller)
            <tr><td>Atendió</td><td class="right">{{ $ticket->seller->name }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>
    <table>
        @foreach ($ticket->lines as $line)
            <tr><td colspan="2">{{ $line->product_name }}</td></tr>
            <tr>
                <td>{{ rtrim(rtrim($line->quantity, '0'), '.') }} × {{ $money($line->unit_price) }}</td>
                <td class="right">{{ $money($line->gross_amount) }}</td>
            </tr>
            @if ((float) $line->discount > 0)
                <tr><td>Descuento</td><td class="right">−{{ $money($line->discount) }}</td></tr>
            @endif
        @endforeach
    </table>

    <div class="rule"></div>
    <table>
        @if ((float) $ticket->discount_total > 0)
            <tr><td>Descuentos</td><td class="right">−{{ $money($ticket->discount_total) }}</td></tr>
        @endif
        <tr class="bold"><td>TOTAL</td><td class="right">{{ $money($ticket->total) }}</td></tr>
        <tr><td>Pago</td><td class="right">{{ $ticket->payment_method->label() }}</td></tr>
    </table>

    <p class="center" style="margin-top: 6pt;">{{ \App\Models\Ticket::LEGAL_NOTICE }}</p>
</body>
</html>

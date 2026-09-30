{{-- Comprobante electrónico (spec 005): A4 y 80 mm con la misma plantilla. --}}
@php($ticket = $format === '80mm')
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: {{ $ticket ? '8pt' : '28pt' }}; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: {{ $ticket ? '7.5pt' : '9pt' }}; color: #111; }
    .beta { border: 1.5pt solid #b45309; color: #92400e; background: #fef3c7; text-align: center; font-weight: bold; padding: 4pt; margin-bottom: 8pt; letter-spacing: 0.5pt; }
    .center { text-align: center; }
    .right { text-align: right; }
    .muted { color: #555; }
    table { width: 100%; border-collapse: collapse; }
    .header td { vertical-align: top; }
    .doc-box { border: 1pt solid #111; text-align: center; padding: 6pt; }
    .doc-box .ruc { font-weight: bold; }
    .doc-box .title { font-weight: bold; font-size: {{ $ticket ? '8.5pt' : '11pt' }}; margin: 3pt 0; }
    .issuer-name { font-weight: bold; font-size: {{ $ticket ? '9pt' : '12pt' }}; }
    .section { margin-top: 8pt; }
    .items th { border-bottom: 1pt solid #111; text-align: left; padding: 3pt 2pt; font-size: {{ $ticket ? '7pt' : '8pt' }}; }
    .items td { border-bottom: 0.5pt solid #ccc; padding: 3pt 2pt; vertical-align: top; }
    .totals td { padding: 2pt; }
    .totals .grand td { font-weight: bold; border-top: 1pt solid #111; font-size: {{ $ticket ? '8.5pt' : '10pt' }}; }
    .qr { width: {{ $ticket ? '90pt' : '100pt' }}; }
    .dashed { border-top: 1pt dashed #999; margin: 6pt 0; }
</style>
</head>
<body>
    <div class="beta">PRUEBAS — SIN VALOR LEGAL</div>

    @if ($ticket)
        <div class="center">
            @if ($logo)<img src="{{ $logo }}" style="max-width: 110pt; max-height: 45pt;"><br>@endif
            <div class="issuer-name">{{ $document->issuer_trade_name ?: $document->issuer_name }}</div>
            <div>{{ $document->issuer_name }}</div>
            <div class="muted">{{ $document->issuer_address }}</div>
            <div class="doc-box section">
                <div class="ruc">RUC {{ $document->issuer_ruc }}</div>
                <div class="title">{{ $title }}</div>
                <div class="ruc">{{ $document->display_number }}</div>
            </div>
        </div>
    @else
        <table class="header">
            <tr>
                <td style="width: 62%;">
                    @if ($logo)<img src="{{ $logo }}" style="max-width: 140pt; max-height: 60pt;"><br>@endif
                    <div class="issuer-name">{{ $document->issuer_trade_name ?: $document->issuer_name }}</div>
                    <div>{{ $document->issuer_name }}</div>
                    <div class="muted">{{ $document->issuer_address }}</div>
                </td>
                <td>
                    <div class="doc-box">
                        <div class="ruc">RUC {{ $document->issuer_ruc }}</div>
                        <div class="title">{{ $title }}</div>
                        <div class="ruc">{{ $document->display_number }}</div>
                    </div>
                </td>
            </tr>
        </table>
    @endif

    <table class="section">
        <tr><td class="muted" style="width: 30%;">Fecha de emisión</td><td>{{ $document->issued_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td></tr>
        <tr><td class="muted">Cliente</td><td>{{ $document->customer_name }}</td></tr>
        @if ($document->customer_document_number !== '-')
            <tr><td class="muted">{{ ['1' => 'DNI', '4' => 'Carné ext.', '6' => 'RUC'][$document->customer_document_type] ?? 'Documento' }}</td><td>{{ $document->customer_document_number }}</td></tr>
        @endif
        @if ($document->customer_address)
            <tr><td class="muted">Dirección</td><td>{{ $document->customer_address }}</td></tr>
        @endif
        <tr><td class="muted">Moneda</td><td>Soles · al contado ({{ $document->payment_method->label() }})</td></tr>
        @if ($document->reference)
            {{-- Nota de crédito (spec 007): documento que modifica y motivo. --}}
            <tr><td class="muted">Documento que modifica</td><td>{{ $document->reference->document_type->label() }} {{ $document->reference->display_number }}</td></tr>
            <tr><td class="muted">Motivo</td><td>{{ $document->note_reason_code->value }} · {{ $document->note_reason_code->label() }}: {{ $document->note_reason }}</td></tr>
        @endif
    </table>

    <table class="items section">
        <thead>
            <tr>
                <th>Cant.</th>
                @unless ($ticket)<th>Unidad</th>@endunless
                <th>Descripción</th>
                <th class="right">P. unit.</th>
                @unless ($ticket)<th class="right">Dscto.</th>@endunless
                <th class="right">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->lines as $line)
                <tr>
                    <td>{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
                    @unless ($ticket)<td>{{ $line->unit }}</td>@endunless
                    <td>
                        {{ $line->product_name }}
                        @if ($line->igv_affectation !== '10')<span class="muted">({{ $line->igv_affectation === '20' ? 'exonerado' : 'inafecto' }})</span>@endif
                        @if ($ticket && bccomp($line->discount, '0', 2) > 0)<br><span class="muted">Dscto. {{ $line->discount }}</span>@endif
                    </td>
                    <td class="right">{{ $line->unit_price }}</td>
                    @unless ($ticket)<td class="right">{{ bccomp($line->discount, '0', 2) > 0 ? $line->discount : '' }}</td>@endunless
                    <td class="right">{{ $line->amount }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="section">
        <tr>
            @unless ($ticket)
                <td style="width: 55%; vertical-align: top;">
                    <div><strong>{{ $amountInWords }}</strong></div>
                    <div class="section"><img class="qr" src="{{ $qr }}"></div>
                    <div class="muted">Resumen: {{ $document->hash }}</div>
                </td>
            @endunless
            <td style="vertical-align: top;">
                <table class="totals">
                    @if (bccomp($document->op_gravadas, '0', 2) > 0)<tr><td>Op. gravadas</td><td class="right">S/ {{ $document->op_gravadas }}</td></tr>@endif
                    @if (bccomp($document->op_exoneradas, '0', 2) > 0)<tr><td>Op. exoneradas</td><td class="right">S/ {{ $document->op_exoneradas }}</td></tr>@endif
                    @if (bccomp($document->op_inafectas, '0', 2) > 0)<tr><td>Op. inafectas</td><td class="right">S/ {{ $document->op_inafectas }}</td></tr>@endif
                    @if (bccomp($document->discount_total, '0', 2) > 0)<tr><td>Descuentos (incluidos)</td><td class="right">S/ {{ $document->discount_total }}</td></tr>@endif
                    <tr><td>IGV (18 %)</td><td class="right">S/ {{ $document->igv }}</td></tr>
                    <tr class="grand"><td>Importe total</td><td class="right">S/ {{ $document->total }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($ticket)
        <div class="section"><strong>{{ $amountInWords }}</strong></div>
        <div class="center section"><img class="qr" src="{{ $qr }}"></div>
        <div class="center muted">Resumen: {{ $document->hash }}</div>
    @endif

    <div class="dashed"></div>
    <div class="center muted">Representación impresa de la {{ ['01' => 'factura', '03' => 'boleta de venta', '07' => 'nota de crédito'][$document->document_type->value] }} electrónica emitida en el ambiente de pruebas de SUNAT.</div>
    <div class="center" style="font-weight: bold; margin-top: 4pt;">PRUEBAS — SIN VALOR LEGAL</div>
</body>
</html>

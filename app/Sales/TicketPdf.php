<?php

namespace App\Sales;

use App\Models\Ticket;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF de 80 mm del ticket interno (spec 013, A-63): la app Android no puede
 * imprimir el HTML del navegador, así que comparte este PDF. Mismo contenido
 * que el ticket en pantalla y siempre la leyenda de documento interno (A-17).
 */
final class TicketPdf
{
    /** Ancho del papel térmico en puntos (80 mm), como DocumentPdf. */
    private const WIDTH_PT = 226.77;

    public function html(Ticket $ticket): string
    {
        return view('pdf.ticket', [
            'ticket' => $ticket,
            'companyName' => $ticket->company->nombre_comercial ?: $ticket->company->razon_social,
        ])->render();
    }

    public function render(Ticket $ticket): string
    {
        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('Courier'));
        $dompdf->loadHtml($this->html($ticket), 'UTF-8');
        // Papel continuo: alto aproximado según las líneas.
        $dompdf->setPaper([0, 0, self::WIDTH_PT, 220 + 36 * $ticket->lines->count()]);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}

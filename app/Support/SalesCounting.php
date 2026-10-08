<?php

namespace App\Support;

use App\Enums\SalesDocumentStatus;
use App\Enums\TicketStatus;
use App\Models\SalesDocument;
use App\Models\Ticket;

/**
 * Qué cuenta como venta (A-54): tickets no anulados y comprobantes no
 * rechazados ni descartados; las notas de crédito con esos mismos estados
 * restan. La usan el panel de inicio (011) y la exportación de ventas (014),
 * para que sus totales cuadren.
 */
final class SalesCounting
{
    /** Estados de comprobante (boleta, factura o nota) que cuentan. */
    public const DOCUMENT_STATUSES = [
        SalesDocumentStatus::Pending,
        SalesDocumentStatus::Sent,
        SalesDocumentStatus::Accepted,
        SalesDocumentStatus::Observed,
    ];

    public static function ticketCounts(Ticket $ticket): bool
    {
        return $ticket->status === TicketStatus::Issued;
    }

    public static function documentCounts(SalesDocument $document): bool
    {
        return in_array($document->status, self::DOCUMENT_STATUSES, true);
    }
}

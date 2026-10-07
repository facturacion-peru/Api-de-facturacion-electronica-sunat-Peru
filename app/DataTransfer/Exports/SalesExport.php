<?php

namespace App\DataTransfer\Exports;

use App\DataTransfer\CustomerColumns;
use App\DataTransfer\ExportFile;
use App\DataTransfer\Spreadsheet;
use App\Enums\DocumentType;
use App\Models\SalesDocument;
use App\Models\Ticket;
use App\Models\User;
use App\Support\SalesCounting;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Ventas de un rango de fechas de Lima (spec 014, HU-3, A-74): una hoja por
 * documento y otra por línea. Tickets y comprobantes se recorren por bloques
 * y se intercalan por fecha. Las notas de crédito van en negativo, y
 * `suma_como_venta` aplica la regla del panel de inicio (A-54).
 */
class SalesExport
{
    private const CHUNK = 500;

    public const TYPES = [
        'ticket' => null,
        'receipt' => DocumentType::Receipt,
        'invoice' => DocumentType::Invoice,
        'credit_note' => DocumentType::CreditNote,
    ];

    private const DOCUMENTS = [
        'fecha' => Spreadsheet::TEXT,
        'tipo' => Spreadsheet::TEXT,
        'numero' => Spreadsheet::TEXT,
        'cliente_tipo_documento' => Spreadsheet::TEXT,
        'cliente_numero_documento' => Spreadsheet::TEXT,
        'cliente_nombre' => Spreadsheet::TEXT,
        'vendedor' => Spreadsheet::TEXT,
        'medio_pago' => Spreadsheet::TEXT,
        'op_gravadas' => Spreadsheet::MONEY,
        'op_exoneradas' => Spreadsheet::MONEY,
        'op_inafectas' => Spreadsheet::MONEY,
        'igv' => Spreadsheet::MONEY,
        'descuento' => Spreadsheet::MONEY,
        'total' => Spreadsheet::MONEY,
        'estado' => Spreadsheet::TEXT,
        'documento_modificado' => Spreadsheet::TEXT,
        'suma_como_venta' => Spreadsheet::TEXT,
    ];

    private const LINES = [
        'numero' => Spreadsheet::TEXT,
        'fecha' => Spreadsheet::TEXT,
        'tipo' => Spreadsheet::TEXT,
        'codigo' => Spreadsheet::TEXT,
        'producto' => Spreadsheet::TEXT,
        'unidad' => Spreadsheet::TEXT,
        'cantidad' => Spreadsheet::QUANTITY,
        'precio_unitario' => Spreadsheet::MONEY,
        'descuento' => Spreadsheet::MONEY,
        'importe' => Spreadsheet::MONEY,
        'estado_documento' => Spreadsheet::TEXT,
    ];

    /** @var array<int, string> */
    private array $sellers = [];

    /** @param  array{from: string, to: string, types: list<string>, statuses: list<string>}  $filters */
    public function build(string $format, array $filters): ExportFile
    {
        $sheet = new Spreadsheet($format);

        $sheet->sheet('documentos', self::DOCUMENTS);
        foreach ($this->sales($filters, withLines: false) as $sale) {
            $sheet->row($sale instanceof Ticket ? $this->ticketRow($sale) : $this->documentRow($sale));
        }

        $sheet->sheet('lineas', self::LINES);
        foreach ($this->sales($filters, withLines: true) as $sale) {
            foreach ($this->lineRows($sale) as $row) {
                $sheet->row($row);
            }
        }

        return $sheet->finish("ventas-{$filters['from']}_{$filters['to']}");
    }

    /**
     * Tickets y comprobantes del rango, intercalados por fecha de emisión.
     *
     * @param  array{from: string, to: string, types: list<string>, statuses: list<string>}  $filters
     * @return Generator<Ticket|SalesDocument>
     */
    private function sales(array $filters, bool $withLines): Generator
    {
        $types = $filters['types'] === [] ? array_keys(self::TYPES) : $filters['types'];
        $range = fn (Builder $q) => $q
            ->whereBetween('issued_at', [Carbon::parse($filters['from'])->startOfDay(), Carbon::parse($filters['to'])->endOfDay()])
            ->when($filters['statuses'] !== [], fn ($q) => $q->whereIn('status', $filters['statuses']))
            ->when($withLines, fn ($q) => $q->with('lines'))
            ->orderBy('issued_at')->orderBy('id');

        $tickets = in_array('ticket', $types, true)
            ? Ticket::query()->tap($range)->lazy(self::CHUNK)->getIterator()
            : new \EmptyIterator;

        $documentTypes = array_values(array_filter(array_map(fn (string $type) => self::TYPES[$type], $types)));
        $documents = $documentTypes !== []
            ? SalesDocument::query()->tap($range)->whereIn('document_type', $documentTypes)
                ->with('reference:id,series_code,number')->lazy(self::CHUNK)->getIterator()
            : new \EmptyIterator;

        $tickets->rewind();
        $documents->rewind();
        while ($tickets->valid() || $documents->valid()) {
            $takeTicket = ! $documents->valid()
                || ($tickets->valid() && $tickets->current()->issued_at <= $documents->current()->issued_at);
            $source = $takeTicket ? $tickets : $documents;
            yield $source->current();
            $source->next();
        }
    }

    /** @return list<string|null> */
    private function ticketRow(Ticket $ticket): array
    {
        return [
            $ticket->issued_at->format('Y-m-d H:i:s'),
            'Ticket',
            $ticket->display_number,
            null,
            $ticket->customer_document,
            $ticket->customer_name,
            $this->seller($ticket->seller_id),
            $ticket->payment_method->label(),
            null, null, null, null,
            $ticket->discount_total,
            $ticket->total,
            $ticket->status->label(),
            null,
            SalesCounting::ticketCounts($ticket) ? 'si' : 'no',
        ];
    }

    /** @return list<string|null> */
    private function documentRow(SalesDocument $document): array
    {
        $sign = fn (?string $amount) => $this->signed($document, $amount);
        $hasCustomerDocument = array_key_exists((string) $document->customer_document_type, CustomerColumns::DOCUMENT_TYPES);

        return [
            $document->issued_at->format('Y-m-d H:i:s'),
            $document->document_type->label(),
            $document->display_number,
            $hasCustomerDocument ? CustomerColumns::DOCUMENT_TYPES[$document->customer_document_type] : null,
            $hasCustomerDocument ? $document->customer_document_number : null,
            $document->customer_name,
            $this->seller($document->seller_id),
            $document->payment_method?->label(),
            $sign($document->op_gravadas),
            $sign($document->op_exoneradas),
            $sign($document->op_inafectas),
            $sign($document->igv),
            $sign($document->discount_total),
            $sign($document->total),
            $document->status->label(),
            $document->reference?->display_number,
            SalesCounting::documentCounts($document) ? 'si' : 'no',
        ];
    }

    /** @return Generator<list<string|null>> */
    private function lineRows(Ticket|SalesDocument $sale): Generator
    {
        $isTicket = $sale instanceof Ticket;
        $sign = fn (?string $amount) => $isTicket ? $amount : $this->signed($sale, $amount);

        foreach ($sale->lines as $line) {
            yield [
                $sale->display_number,
                $sale->issued_at->format('Y-m-d H:i:s'),
                $isTicket ? 'Ticket' : $sale->document_type->label(),
                $line->product_code,
                $line->product_name,
                is_string($line->unit) ? $line->unit : $line->unit?->value,
                $line->quantity,
                $line->unit_price,
                $sign($line->discount),
                $sign($line->amount),
                $sale->status->label(),
            ];
        }
    }

    /** Las notas de crédito restan: sus importes van en negativo (A-74). */
    private function signed(SalesDocument $document, ?string $amount): ?string
    {
        if ($amount === null || $document->document_type !== DocumentType::CreditNote || bccomp($amount, '0', 2) === 0) {
            return $amount;
        }

        return bcmul($amount, '-1', 2);
    }

    private function seller(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->sellers[$id] ??= (string) User::query()->whereKey($id)->value('name');
    }
}

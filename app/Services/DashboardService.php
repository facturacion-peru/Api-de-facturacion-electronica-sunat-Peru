<?php

namespace App\Services;

use App\Enums\CompanyRole;
use App\Enums\DocumentType;
use App\Enums\SalesDocumentStatus;
use App\Enums\TicketStatus;
use App\Models\SalesDocument;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Indicadores del panel de inicio (spec 011). Se calculan al pedirlos, en la
 * empresa del contexto, con la fecha de Lima (`app.timezone`, RF-002).
 *
 * Ventas (A-54): tickets no anulados + boletas y facturas no rechazadas ni
 * descartadas, menos las notas de crédito con esos mismos estados el día en
 * que se emiten. Es un indicador operativo, no un reporte contable.
 * El vendedor solo ve lo suyo (A-53); los comprobantes por atender son de
 * toda la empresa, como en su listado (A-32).
 */
class DashboardService
{
    /** Estados de comprobante que cuentan como venta (A-54). */
    private const COUNTED = [SalesDocumentStatus::Pending, SalesDocumentStatus::Sent, SalesDocumentStatus::Accepted, SalesDocumentStatus::Observed];

    private const DAYS = 7;

    private const RECENT = 5;

    public function __construct(private InventoryAlertService $alerts) {}

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $own = ! $user->hasCompanyRole(CompanyRole::CompanyAdmin);
        $today = today();
        $from = $today->copy()->subDays(self::DAYS - 1);
        $days = $this->days($user, $own, $from, $today->copy()->endOfDay());
        $last = $days[array_key_last($days)];

        return [
            'date' => $today->toDateString(),
            'scope' => $own ? 'own' : 'company',
            'today' => ['total' => $last['total'], 'count' => $last['count']],
            'last_7_days' => $days,
            'attention' => [
                'pending_documents' => SalesDocument::query()->whereIn('status', [SalesDocumentStatus::Pending, SalesDocumentStatus::Sent])->count(),
                'rejected_documents' => SalesDocument::query()->where('status', SalesDocumentStatus::Rejected)->count(),
                'inventory_alerts' => $own ? null : $this->alerts->count(),
            ],
            'recent_sales' => $this->recent($user, $own),
        ];
    }

    /** @return list<array{date: string, total: string, count: int}> */
    private function days(User $user, bool $own, Carbon $from, Carbon $to): array
    {
        $range = fn (Builder $q) => $q->whereBetween('issued_at', [$from, $to]);

        $tickets = Ticket::query()->tap($range)
            ->where('status', TicketStatus::Issued)
            ->when($own, fn ($q) => $q->where('seller_id', $user->id))
            ->get(['total', 'issued_at']);

        $documents = SalesDocument::query()->tap($range)
            ->whereIn('document_type', [DocumentType::Invoice, DocumentType::Receipt])
            ->whereIn('status', self::COUNTED)
            ->when($own, fn ($q) => $q->where('seller_id', $user->id))
            ->get(['total', 'issued_at']);

        // La nota resta a quien hizo la venta que corrige.
        $notes = SalesDocument::query()->tap($range)
            ->where('document_type', DocumentType::CreditNote)
            ->whereIn('status', self::COUNTED)
            ->when($own, fn ($q) => $q->whereIn('reference_document_id', SalesDocument::query()->select('id')->where('seller_id', $user->id)))
            ->get(['total', 'issued_at']);

        $byDay = fn (Collection $rows) => $rows->groupBy(fn ($row) => $row->issued_at->toDateString());
        [$tickets, $documents, $notes] = [$byDay($tickets), $byDay($documents), $byDay($notes)];

        return collect(range(0, self::DAYS - 1))->map(function (int $offset) use ($from, $tickets, $documents, $notes) {
            $date = $from->copy()->addDays($offset)->toDateString();
            $sales = collect([$tickets->get($date), $documents->get($date)])->filter()->flatten();
            $returned = Decimal::sum(($notes->get($date) ?? collect())->pluck('total'), 2);

            return [
                'date' => $date,
                'total' => bcsub(Decimal::sum($sales->pluck('total'), 2), $returned, 2),
                'count' => $sales->count(),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function recent(User $user, bool $own): array
    {
        $latest = fn (Builder $q) => $q->when($own, fn ($q) => $q->where('seller_id', $user->id))
            ->orderByDesc('issued_at')->orderByDesc('id')->limit(self::RECENT)->get();

        $tickets = $latest(Ticket::query())->map(fn (Ticket $t) => [
            'kind' => 'ticket',
            'id' => $t->id,
            'number' => $t->display_number,
            'document_type' => null,
            'customer_name' => $t->customer_name,
            'total' => $t->total,
            'status' => $t->status->value,
            'issued_at' => $t->issued_at,
        ]);

        $documents = $latest(SalesDocument::query())->map(fn (SalesDocument $d) => [
            'kind' => 'document',
            'id' => $d->id,
            'number' => $d->display_number,
            'document_type' => $d->document_type->value,
            'customer_name' => $d->customer_name,
            'total' => $d->total,
            'status' => $d->status->value,
            'issued_at' => $d->issued_at,
        ]);

        return $tickets->concat($documents)
            ->sortByDesc(fn (array $sale) => $sale['issued_at']->getTimestamp())
            ->take(self::RECENT)
            ->map(fn (array $sale) => [...$sale, 'issued_at' => $sale['issued_at']->toIso8601String()])
            ->values()
            ->all();
    }
}

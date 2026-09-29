<?php

namespace App\Http\Controllers\Api\Sales;

use App\Enums\CompanyRole;
use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\IndexTicketRequest;
use App\Http\Requests\Sales\StoreTicketRequest;
use App\Http\Requests\Sales\VoidTicketRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Ventas con ticket interno (spec 003). */
class TicketController extends Controller
{
    public function __construct(private TicketService $tickets) {}

    /** 201 si se creó; 200 si la clave de idempotencia ya existía (RF-008). */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        [$ticket, $created] = $this->tickets->issue($request->validated(), $request->user());

        return TicketResource::make($ticket->load(['lines', 'seller']))->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * Ventas filtradas, de la más reciente a la más antigua, con totales por
     * medio de pago sin anulados (HU-4.1). El vendedor solo ve las suyas (HU-4.2).
     */
    public function index(IndexTicketRequest $request): ApiCollection
    {
        $filters = $request->validated();
        $query = $this->scoped($request->user())
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('issued_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('issued_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when(
                isset($filters['seller_id']) && $request->user()->hasCompanyRole(CompanyRole::CompanyAdmin),
                fn ($q) => $q->where('seller_id', $filters['seller_id']),
            );

        $page = (clone $query)->with('seller')->orderByDesc('issued_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return TicketResource::collection($page)->additional(['totals' => $this->totals($query)]);
    }

    public function show(Request $request, Ticket $ticket): TicketResource
    {
        // El vendedor no ve tickets ajenos: 404, como un recurso inexistente.
        abort_unless($this->scoped($request->user())->whereKey($ticket->id)->exists(), 404);

        return TicketResource::make($ticket->load(['lines', 'seller']));
    }

    public function void(VoidTicketRequest $request, Ticket $ticket): TicketResource
    {
        $ticket = $this->tickets->void($ticket, $request->validated('reason'), $request->user());

        return TicketResource::make($ticket->load(['lines', 'seller']));
    }

    /** @return Builder<Ticket> */
    private function scoped(User $user): Builder
    {
        return Ticket::query()->unless(
            $user->hasCompanyRole(CompanyRole::CompanyAdmin),
            fn ($q) => $q->where('seller_id', $user->id),
        );
    }

    /**
     * Suma en PHP con bcmath (exacto también en SQLite).
     *
     * @param  Builder<Ticket>  $query
     * @return array{count: int, total: string, by_payment_method: array<string, string>}
     */
    private function totals(Builder $query): array
    {
        $issued = (clone $query)->where('status', TicketStatus::Issued)->get(['payment_method', 'total']);
        $byMethod = [];

        foreach (PaymentMethod::cases() as $method) {
            $byMethod[$method->value] = Decimal::sum($issued->where('payment_method', $method)->pluck('total'), 2);
        }

        return [
            'count' => $issued->count(),
            'total' => Decimal::sum($issued->pluck('total'), 2),
            'by_payment_method' => $byMethod,
        ];
    }
}

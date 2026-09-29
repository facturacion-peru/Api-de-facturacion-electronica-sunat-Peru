<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreTicketRequest;
use App\Http\Requests\Sales\VoidTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;

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

    public function show(Ticket $ticket): TicketResource
    {
        return TicketResource::make($ticket->load(['lines', 'seller']));
    }

    public function void(VoidTicketRequest $request, Ticket $ticket): TicketResource
    {
        $ticket = $this->tickets->void($ticket, $request->validated('reason'), $request->user());

        return TicketResource::make($ticket->load(['lines', 'seller']));
    }
}

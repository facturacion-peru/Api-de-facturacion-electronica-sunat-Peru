<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\TicketSequence;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Ventas con ticket interno (spec 003). */
class TicketService
{
    public function __construct(
        private AuditLogger $audit,
        private InventoryService $inventory,
    ) {}

    /**
     * Emite un ticket en una sola transacción: numera sin huecos, copia los
     * datos y precios del catálogo, calcula importes exactos y descuenta el
     * stock. Si algo falla (p. ej. InsufficientStock) no queda nada, ni el
     * número. La misma clave de idempotencia devuelve el ticket existente.
     *
     * @param  array{idempotency_key: string, payment_method: string, customer_name?: ?string, customer_document?: ?string, lines: list<array{product_id: int, quantity: string, discount?: ?string}>}  $data
     * @return array{Ticket, bool} el ticket y si se creó ahora
     */
    public function issue(array $data, User $actor): array
    {
        if ($existing = $this->findByKey($data['idempotency_key'])) {
            return [$existing, false];
        }

        try {
            return [DB::transaction(fn () => $this->create($data, $actor)), true];
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones simultáneas con la misma clave: gana la primera.
            if ($existing = $this->findByKey($data['idempotency_key'])) {
                return [$existing, false];
            }

            throw $e;
        }
    }

    /**
     * Anula el ticket (HU-3): revierte sus ventas para devolver el stock y
     * conserva el número. Solo el administrador (lo controla la request).
     */
    public function void(Ticket $ticket, string $reason, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $reason, $actor) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($ticket->status === TicketStatus::Voided) {
                throw ValidationException::withMessages(['ticket' => 'El ticket ya está anulado.']);
            }

            InventoryMovement::query()
                ->where('source_type', $ticket->getMorphClass())
                ->where('source_id', $ticket->id)
                ->where('type', MovementType::Sale)
                ->whereDoesntHave('reversal')
                ->orderBy('id')
                ->get()
                ->each(fn (InventoryMovement $sale) => $this->inventory->reverse(
                    $sale, AdjustmentReason::Error, "Anulación del ticket {$ticket->display_number}", $actor, fromSource: true,
                ));

            $ticket->update([
                'status' => TicketStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            $this->audit->record('ticket.voided', $ticket, [
                'number' => $ticket->display_number,
                'reason' => $reason,
            ], actor: $actor);

            return $ticket;
        });
    }

    private function create(array $data, User $actor): Ticket
    {
        $companyId = $actor->membership->company_id;
        $products = Product::whereIn('id', collect($data['lines'])->pluck('product_id'))->get()->keyBy('id');

        $lines = [];
        foreach ($data['lines'] as $i => $line) {
            $product = $products[$line['product_id']];
            $quantity = Decimal::fromDb((string) $line['quantity']);
            $gross = Decimal::mul($quantity, $product->sale_price);
            $discount = Decimal::round((string) ($line['discount'] ?? '0'));

            $lines[] = [
                'product' => $product,
                'position' => $i + 1,
                'quantity' => $quantity,
                'unit_price' => $product->sale_price,
                'gross_amount' => $gross,
                'discount' => $discount,
                'amount' => bcsub($gross, $discount, 2),
            ];
        }

        $ticket = Ticket::create([
            'company_id' => $companyId,
            'number' => $this->nextNumber($companyId),
            'status' => TicketStatus::Issued,
            'seller_id' => $actor->id,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_document' => $data['customer_document'] ?? null,
            'payment_method' => PaymentMethod::from($data['payment_method']),
            'subtotal' => Decimal::sum(array_column($lines, 'gross_amount'), 2),
            'discount_total' => Decimal::sum(array_column($lines, 'discount'), 2),
            'total' => Decimal::sum(array_column($lines, 'amount'), 2),
            'idempotency_key' => $data['idempotency_key'],
            'issued_at' => now(),
        ]);

        foreach ($lines as $line) {
            $product = $line['product'];

            TicketLine::create([
                'company_id' => $companyId,
                'ticket_id' => $ticket->id,
                'product_id' => $product->id,
                'position' => $line['position'],
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit' => $product->unit->value,
                'igv_affectation' => $product->igv_affectation->value,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'gross_amount' => $line['gross_amount'],
                'discount' => $line['discount'],
                'amount' => $line['amount'],
            ]);

            $this->inventory->consume($product, $line['quantity'], $ticket, $actor);
        }

        $this->audit->record('ticket.issued', $ticket, [
            'number' => $ticket->display_number,
            'total' => $ticket->total,
            'payment_method' => $ticket->payment_method->value,
        ], actor: $actor);

        return $ticket;
    }

    /** Bloquea la secuencia de la empresa hasta el final de la transacción. */
    private function nextNumber(int $companyId): int
    {
        // Crea la fila si es la primera venta, sin chocar con otra simultánea.
        DB::table('ticket_sequences')->insertOrIgnore(['company_id' => $companyId, 'last_number' => 0]);

        $sequence = TicketSequence::whereKey($companyId)->lockForUpdate()->firstOrFail();
        $sequence->last_number++;
        $sequence->save();

        return $sequence->last_number;
    }

    private function findByKey(string $key): ?Ticket
    {
        return Ticket::where('idempotency_key', $key)->first();
    }
}

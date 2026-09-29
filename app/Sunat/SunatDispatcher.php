<?php

namespace App\Sunat;

use App\Audit\AuditLogger;
use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionResult;
use App\Enums\SubmissionTrigger;
use App\Models\SalesDocument;
use App\Models\SunatSubmission;
use App\Models\User;
use App\Sunat\Sending\SunatResponse;
use App\Sunat\Sending\SunatSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Envía un comprobante ya guardado y firmado a SUNAT (spec 005, HU-4).
 *
 * - Un *lease* en la fila (`locked_until`) impide dos envíos simultáneos
 *   (RF-012) sin mantener una transacción abierta durante la llamada SOAP.
 * - Si SUNAT no responde, el comprobante vuelve a pendiente con espera
 *   creciente; el comando `sunat:send-pending` lo retoma (A-23).
 * - Cada intento queda en `sunat_submissions` (RF-013) y los cambios de
 *   estado se auditan (RF-022).
 */
class SunatDispatcher
{
    /** Espera tras cada intento fallido, en minutos; luego cada hora (plan 005). */
    public const BACKOFF_MINUTES = [1, 2, 5, 10, 30];

    /** Pasado este plazo desde la emisión solo queda el reintento manual. */
    public const RETRY_WINDOW_HOURS = 24;

    /** Duración del lease: holgada frente al tiempo límite del envío. */
    public const LEASE_SECONDS = 120;

    public function __construct(
        private SunatSender $sender,
        private AuditLogger $audit,
    ) {}

    /**
     * @return bool false si otro proceso ya lo está enviando o el estado es definitivo
     */
    public function send(SalesDocument $document, SubmissionTrigger $trigger, ?User $actor = null): bool
    {
        if (! $this->acquire($document)) {
            return false;
        }

        $document->refresh();
        $startedAt = now();
        $timer = hrtime(true);
        $response = $this->sender->send($document->xml, $document->issuer_ruc);

        // Beta responde 401 si se envía segundos después de otro documento
        // (spike T002): al emitir se reintenta una vez tras una pausa corta.
        if ($trigger === SubmissionTrigger::Issue && $this->isBetaRateLimit($response)) {
            sleep((int) config('services.sunat.rate_limit_pause'));
            $response = $this->sender->send($document->xml, $document->issuer_ruc);
        }

        $this->record($document, $trigger, $actor, $startedAt, $timer, $response);
        $this->apply($document, $response, $actor);

        return true;
    }

    /** Siguiente reintento automático tras N intentos fallidos, o null si ya pasó el plazo. */
    public static function nextAttemptAt(int $attempts, Carbon $issuedAt): ?Carbon
    {
        $minutes = self::BACKOFF_MINUTES[$attempts - 1] ?? 60;
        $next = now()->addMinutes($minutes);

        return $next->greaterThan($issuedAt->copy()->addHours(self::RETRY_WINDOW_HOURS)) ? null : $next;
    }

    /** Toma el lease con un UPDATE condicional: solo un proceso lo consigue. */
    private function acquire(SalesDocument $document): bool
    {
        return DB::table('sales_documents')
            ->where('id', $document->id)
            ->whereIn('status', [SalesDocumentStatus::Pending->value, SalesDocumentStatus::Sent->value])
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update([
                'status' => SalesDocumentStatus::Sent->value,
                'locked_until' => now()->addSeconds(self::LEASE_SECONDS),
                'updated_at' => now(),
            ]) === 1;
    }

    private function isBetaRateLimit(SunatResponse $response): bool
    {
        return $response->result === SubmissionResult::Unreachable
            && $response->code === 'HTTP'
            && str_contains((string) $response->message, 'Unauthorized');
    }

    private function record(SalesDocument $document, SubmissionTrigger $trigger, ?User $actor, Carbon $startedAt, int $timer, SunatResponse $response): void
    {
        SunatSubmission::create([
            'company_id' => $document->company_id,
            'sales_document_id' => $document->id,
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'duration_ms' => intdiv(hrtime(true) - $timer, 1_000_000),
            'result' => $response->result,
            'code' => $response->code,
            'message' => $response->message !== null ? mb_substr($response->message, 0, 1000) : null,
            'user_id' => $actor?->id,
        ]);
    }

    private function apply(SalesDocument $document, SunatResponse $response, ?User $actor): void
    {
        $attempts = $document->attempts + 1;
        $from = SalesDocumentStatus::Pending;

        $final = match ($response->result) {
            SubmissionResult::Accepted => SalesDocumentStatus::Accepted,
            SubmissionResult::Observed => SalesDocumentStatus::Observed,
            SubmissionResult::Rejected => SalesDocumentStatus::Rejected,
            default => null,
        };

        if ($final !== null) {
            $document->update([
                'status' => $final,
                'sunat_code' => $response->code,
                'sunat_message' => $response->message,
                'sunat_notes' => $response->notes,
                'cdr' => $response->cdrZip !== null ? base64_encode($response->cdrZip) : null,
                'attempts' => $attempts,
                'next_attempt_at' => null,
                'locked_until' => null,
            ]);

            $this->audit->record('sales_document.status_changed', $document, [
                'number' => $document->display_number,
                'status' => ['from' => $from->value, 'to' => $final->value],
                'sunat_code' => $response->code,
            ], actor: $actor);

            return;
        }

        $document->update([
            'status' => SalesDocumentStatus::Pending,
            'sunat_code' => $response->code,
            'sunat_message' => $response->message,
            'attempts' => $attempts,
            'next_attempt_at' => self::nextAttemptAt($attempts, $document->issued_at),
            'locked_until' => null,
        ]);
    }
}

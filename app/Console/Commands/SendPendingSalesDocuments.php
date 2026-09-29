<?php

namespace App\Console\Commands;

use App\Enums\SalesDocumentStatus;
use App\Enums\SubmissionTrigger;
use App\Models\SalesDocument;
use App\Sunat\SunatDispatcher;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Reintentos automáticos de envío a SUNAT (spec 005, A-23). Corre cada
 * minuto en el scheduler: toma los pendientes cuyo reintento venció y los
 * envíos cuyo lease caducó (un proceso que murió a mitad de envío).
 */
class SendPendingSalesDocuments extends Command
{
    protected $signature = 'sunat:send-pending {--limit=50 : Máximo de comprobantes por ejecución}';

    protected $description = 'Reenvía a SUNAT los comprobantes pendientes cuyo reintento ya venció';

    public function handle(SunatDispatcher $dispatcher, TenantContext $tenant): int
    {
        $documents = SalesDocument::withoutTenancy()
            ->with('company')
            ->where(fn ($q) => $q
                ->where(fn ($pending) => $pending->where('status', SalesDocumentStatus::Pending)->where('next_attempt_at', '<=', now()))
                ->orWhere(fn ($stale) => $stale->where('status', SalesDocumentStatus::Sent)->where('locked_until', '<', now())))
            ->orderBy('next_attempt_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $sent = 0;
        foreach ($documents as $document) {
            $sent += (int) $tenant->run($document->company, fn () => $dispatcher->send($document, SubmissionTrigger::Scheduled));
        }

        $this->info("Comprobantes enviados: {$sent} de {$documents->count()}.");

        return self::SUCCESS;
    }
}

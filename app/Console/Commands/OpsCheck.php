<?php

namespace App\Console\Commands;

use App\Enums\SalesDocumentStatus;
use App\Models\SalesDocument;
use App\Notifications\OpsAlert;
use App\Ops\DiskUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Revisión de operación cada 15 minutos (spec 009, A-48): comprobantes
 * pendientes de SUNAT hace más de una hora y disco sobre el umbral. Cada
 * problema avisa como máximo una vez cada `ops.repeat_hours`; al resolverse
 * se olvida, para avisar enseguida si vuelve.
 */
class OpsCheck extends Command
{
    protected $signature = 'ops:check';

    protected $description = 'Revisa pendientes SUNAT y disco, y avisa al responsable del SaaS';

    public function handle(DiskUsage $disk): int
    {
        $minutes = (int) config('ops.pending_minutes');
        $pending = SalesDocument::withoutTenancy()
            ->whereIn('status', [SalesDocumentStatus::Pending, SalesDocumentStatus::Sent])
            ->where('issued_at', '<', now()->subMinutes($minutes));
        $count = (clone $pending)->count();
        $companies = (clone $pending)->distinct()->count('company_id');

        $this->check('sunat-pending', $count > 0, 'Comprobantes pendientes de SUNAT',
            "Hay {$count} comprobantes pendientes de envío hace más de {$minutes} minutos, de {$companies} empresa(s). Revisa el panel de soporte y el scheduler.");

        $used = $disk->percentUsed((string) config('ops.disk_path'));
        $threshold = (int) config('ops.disk_threshold_percent');
        $this->check('disk', $used >= $threshold, 'Disco casi lleno',
            'El disco del servidor está al '.round($used)." % (umbral {$threshold} %). Libera espacio antes de que falle la base de datos.");

        return self::SUCCESS;
    }

    private function check(string $key, bool $failing, string $title, string $detail): void
    {
        $cacheKey = "ops-alert:{$key}";

        if (! $failing) {
            Cache::forget($cacheKey);

            return;
        }

        $this->warn("{$title}: {$detail}");

        // add() solo escribe si no existe: un aviso por problema cada N horas.
        if (Cache::add($cacheKey, true, now()->addHours((int) config('ops.repeat_hours')))) {
            Notification::route('mail', config('ops.alert_email'))->notify(new OpsAlert($key, $title, $detail));
        }
    }
}

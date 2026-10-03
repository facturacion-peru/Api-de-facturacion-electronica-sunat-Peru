<?php

namespace App\Ops;

use App\Inventory\Exceptions\InsufficientStock;
use App\Notifications\OpsAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Aviso de errores 500 repetidos (spec 009, A-48). Cada error se identifica
 * por su clase y su lugar (archivo:línea), nunca por su mensaje, que puede
 * llevar datos de clientes o secretos. Avisa cuando el mismo error se repite
 * `ops.error_threshold` veces en `ops.error_window_minutes`, como máximo una
 * vez cada `ops.error_mail_every_minutes`. Nunca lanza: reportar no debe
 * convertir un error en otro.
 */
class ErrorAlerter
{
    /** Excepciones de dominio que la API responde como 4xx: no son fallas. */
    private const EXPECTED = [InsufficientStock::class];

    public function report(Throwable $e): void
    {
        if (in_array($e::class, self::EXPECTED, true)) {
            return;
        }

        try {
            $where = str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine();
            $signature = sha1($e::class.'|'.$where);
            $countKey = "ops-error-count:{$signature}";

            Cache::add($countKey, 0, now()->addMinutes((int) config('ops.error_window_minutes')));
            $count = Cache::increment($countKey);

            if ($count >= (int) config('ops.error_threshold')
                && Cache::add("ops-error-mail:{$signature}", true, now()->addMinutes((int) config('ops.error_mail_every_minutes')))) {
                Notification::route('mail', config('ops.alert_email'))->notify(new OpsAlert(
                    "error:{$signature}",
                    'Errores 500 repetidos en la API',
                    'El error '.$e::class." en {$where} ocurrió {$count} veces en los últimos "
                        .config('ops.error_window_minutes').' minutos. El detalle está en el log del servidor.',
                ));
            }
        } catch (Throwable) {
            // El log ya registra el error original.
        }
    }
}

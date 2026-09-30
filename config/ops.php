<?php

/*
 * Operación del SaaS (spec 009, A-48): avisos al responsable y monitores.
 */
return [
    // Destinatario de todos los avisos de operación.
    'alert_email' => env('OPS_ALERT_EMAIL', 'alertas@example.com'),

    // Monitores de latidos externos: si dejan de recibir el ping, avisan.
    'heartbeat_url' => env('OPS_HEARTBEAT_URL'),
    'backup_ping_url' => env('OPS_BACKUP_PING_URL'),

    // ops:check
    'pending_minutes' => (int) env('OPS_PENDING_MINUTES', 60),
    'disk_threshold_percent' => (int) env('OPS_DISK_THRESHOLD', 80),
    'disk_path' => env('OPS_DISK_PATH', '/'),
    'repeat_hours' => (int) env('OPS_REPEAT_HOURS', 6),

    // Errores 500: aviso si el mismo error se repite N veces en M minutos; uno por hora como máximo.
    'error_threshold' => (int) env('OPS_ERROR_THRESHOLD', 5),
    'error_window_minutes' => (int) env('OPS_ERROR_WINDOW', 10),
    'error_mail_every_minutes' => (int) env('OPS_ERROR_MAIL_EVERY', 60),
];

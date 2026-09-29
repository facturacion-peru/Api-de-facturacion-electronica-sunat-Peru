<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reintentos automáticos de envío a SUNAT (spec 005). En el servidor, cron
// con `php artisan schedule:run` cada minuto; en desarrollo, `schedule:work`.
Schedule::command('sunat:send-pending')->everyMinute()->withoutOverlapping();

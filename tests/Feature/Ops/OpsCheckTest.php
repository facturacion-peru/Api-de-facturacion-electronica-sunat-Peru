<?php

use App\Enums\SalesDocumentStatus;
use App\Models\SalesDocument;
use App\Notifications\OpsAlert;
use App\Ops\DiskUsage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * T013 · ops:check (spec 009, A-48): avisa por pendientes SUNAT de más de
 * 1 hora y por disco sobre el 80 %, sin repetir el mismo aviso en 6 horas.
 */

beforeEach(function () {
    Notification::fake();
    config(['ops.alert_email' => 'ops@saas.test']);
    $this->disk = new class extends DiskUsage
    {
        public float $used = 40.0;

        public function percentUsed(string $path): float
        {
            return $this->used;
        }
    };
    app()->instance(DiskUsage::class, $this->disk);
});

function sentAlerts(): array
{
    return Notification::sent(new AnonymousNotifiable, OpsAlert::class)->all();
}

it('no avisa si todo está bien', function () {
    SalesDocument::factory()->status(SalesDocumentStatus::Pending)->create(['issued_at' => now()->subMinutes(20)]);

    $this->artisan('ops:check')->assertSuccessful();

    Notification::assertNothingSent();
});

it('avisa por comprobantes pendientes hace más de una hora, de cualquier empresa', function () {
    SalesDocument::factory()->status(SalesDocumentStatus::Pending)->create(['issued_at' => now()->subMinutes(90)]);
    SalesDocument::factory()->status(SalesDocumentStatus::Sent)->create(['issued_at' => now()->subHours(3)]);

    $this->artisan('ops:check')->assertSuccessful();

    Notification::assertSentOnDemand(OpsAlert::class, function (OpsAlert $alert, array $channels, AnonymousNotifiable $to) {
        return $to->routes['mail'] === 'ops@saas.test'
            && $alert->key === 'sunat-pending'
            && str_contains($alert->detail, '2 comprobantes');
    });
});

it('avisa si el disco pasa del umbral', function () {
    $this->disk->used = 86.4;

    $this->artisan('ops:check')->assertSuccessful();

    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $alert) => $alert->key === 'disk' && str_contains($alert->detail, '86 %'));
});

it('no repite el mismo aviso dentro de 6 horas, y vuelve a avisar después', function () {
    $this->disk->used = 90.0;

    $this->artisan('ops:check');
    $this->travel(5)->hours();
    $this->artisan('ops:check');
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);

    $this->travel(2)->hours();
    $this->artisan('ops:check');
    Notification::assertSentOnDemandTimes(OpsAlert::class, 2);
});

it('si el problema se resuelve, un problema nuevo avisa enseguida', function () {
    $this->disk->used = 90.0;
    $this->artisan('ops:check');
    $this->disk->used = 50.0;
    $this->artisan('ops:check');
    $this->disk->used = 91.0;
    $this->artisan('ops:check');

    Notification::assertSentOnDemandTimes(OpsAlert::class, 2);
});

it('el aviso por correo no lleva datos de clientes ni importes', function () {
    SalesDocument::factory()->status(SalesDocumentStatus::Pending)->create(['issued_at' => now()->subHours(2), 'customer_name' => 'CLIENTA CONFIDENCIAL', 'total' => '987.65']);

    $this->artisan('ops:check');

    Notification::assertSentOnDemand(OpsAlert::class, function (OpsAlert $alert) {
        $body = implode(' ', $alert->toMail(new AnonymousNotifiable)->introLines);

        return ! str_contains($body, 'CLIENTA CONFIDENCIAL') && ! str_contains($body, '987.65');
    });
});

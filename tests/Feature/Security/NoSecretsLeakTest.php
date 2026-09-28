<?php

use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\EstablishmentFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
 * T092 · CE-003: tras un recorrido completo, ninguna contraseña aparece en
 * respuestas, logs, auditoría ni en ninguna tabla en texto plano.
 */

it('ninguna contraseña aparece en respuestas, logs ni base de datos', function () {
    Notification::fake();
    EstablishmentFactory::ensureLimaUbigeo();

    $secrets = [
        'root' => 'Plataforma-Secreta-111',
        'admin' => 'Admin-Secreta-222',
        'seller' => 'Vendedor-Secreta-333',
        'failed' => 'Intento-Secreto-444',
        'reset' => 'Nueva-Secreta-555',
    ];

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $responses = [];
    $call = function (string $method, string $uri, array $data = [], ?string $token = null) use (&$responses) {
        app('auth')->forgetGuards();
        $response = test()->withToken($token ?? '')->json($method, $uri, $data);
        $responses[] = $response->getContent();

        return $response;
    };
    $invitationToken = function (string $email): string {
        $token = null;
        Notification::assertSentOnDemand(InvitationNotification::class, function ($n, $c, $notifiable) use ($email, &$token) {
            if ($notifiable->routes['mail'] === $email) {
                $token = $n->token;
            }

            return true;
        });

        return $token;
    };

    User::factory()->platformAdmin()->create(['email' => 'root@example.com', 'password' => $secrets['root']]);
    $rootToken = $call('POST', '/api/v1/auth/login', ['email' => 'root@example.com', 'password' => $secrets['root']])->json('data.token');

    $call('POST', '/api/v1/platform/companies', [
        'ruc' => '20131312955', 'razon_social' => 'Bodega Ana S.A.C.', 'tax_regime' => 'rmt', 'email' => 'bodega@example.com',
        'address' => 'Av. Uno 1', 'ubigeo' => '150101', 'admin_email' => 'ana@example.com',
    ], $rootToken)->assertCreated();

    $adminToken = $call('POST', '/api/v1/invitations/'.$invitationToken('ana@example.com').'/accept', [
        'name' => 'Ana', 'password' => $secrets['admin'], 'password_confirmation' => $secrets['admin'],
    ])->assertCreated()->json('data.token');

    $call('POST', '/api/v1/invitations', ['email' => 'luis@example.com', 'role' => 'seller'], $adminToken)->assertCreated();
    $call('POST', '/api/v1/invitations/'.$invitationToken('luis@example.com').'/accept', [
        'name' => 'Luis', 'password' => $secrets['seller'], 'password_confirmation' => $secrets['seller'],
    ])->assertCreated();

    $call('POST', '/api/v1/auth/login', ['email' => 'luis@example.com', 'password' => $secrets['failed']])->assertUnprocessable();

    $call('POST', '/api/v1/auth/forgot-password', ['email' => 'luis@example.com'])->assertOk();
    $resetToken = null;
    Notification::assertSentTo(User::where('email', 'luis@example.com')->first(), ResetPasswordNotification::class, function ($n) use (&$resetToken) {
        $resetToken = $n->token;

        return true;
    });
    $call('POST', '/api/v1/auth/reset-password', [
        'token' => $resetToken, 'email' => 'luis@example.com',
        'password' => $secrets['reset'], 'password_confirmation' => $secrets['reset'],
    ])->assertOk();

    $luis = User::where('email', 'luis@example.com')->first();
    $call('PATCH', "/api/v1/users/{$luis->id}", ['role' => 'company_admin'], $adminToken)->assertOk();
    $call('PATCH', "/api/v1/users/{$luis->id}", ['active' => false], $adminToken)->assertOk();
    $call('GET', '/api/v1/audit-logs', [], $adminToken)->assertOk();
    $call('GET', '/api/v1/users', [], $adminToken)->assertOk();

    Log::info('fin del recorrido');

    $database = collect(DB::connection()->getSchemaBuilder()->getTableListing())
        ->map(fn (string $table) => json_encode(DB::table($table)->get()))
        ->implode("\n");

    $haystacks = [
        'respuestas' => implode("\n", $responses),
        'logs' => implode("\n", $logged),
        'base de datos' => $database,
    ];

    expect($logged)->not->toBeEmpty();

    foreach ($haystacks as $where => $haystack) {
        foreach ($secrets as $name => $secret) {
            expect(str_contains($haystack, $secret))->toBeFalse("La contraseña «{$name}» aparece en {$where}");
        }
    }
});

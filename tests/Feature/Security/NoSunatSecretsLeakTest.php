<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestCertificates;

/*
 * T050 · CE-002 y RF-002: tras un recorrido completo de configuración, la
 * clave SOL, la contraseña del certificado y la clave privada no aparecen en
 * respuestas, logs, auditoría ni, en claro, en la base de datos.
 */

it('ningún secreto de SUNAT sale en respuestas, logs, auditoría ni base de datos', function () {
    Http::fake(['*' => Http::response('<wsdl:definitions/>', 200)]);
    $company = Company::factory()->withMainEstablishment()->create(['ruc' => '20131312955']);
    $admin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();
    $seller = User::factory()->forCompany($company, CompanyRole::Seller)->create();

    $solPassword = 'Sol-Secreta-Unica-771';
    $certPassword = 'Cert-Pass-Unica-552';
    $certificate = TestCertificates::make(password: $certPassword);
    // Un fragmento del cuerpo de la clave privada, en base64.
    preg_match('/PRIVATE KEY-----\s+(\S{40})/', $certificate['pem'], $match);
    $keyFragment = $match[1];

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $responses = [];
    $call = function (User $as, string $method, string $uri, array $data = []) use (&$responses) {
        app('auth')->forgetGuards();
        $response = test()->withToken($as->createToken('t')->plainTextToken)->json($method, $uri, $data);
        $responses[] = $response->getContent();

        return $response;
    };

    $call($admin, 'PUT', '/api/v1/sunat/credentials', ['sol_user' => 'ventas01', 'sol_password' => $solPassword])->assertOk();
    app('auth')->forgetGuards();
    $upload = test()->withToken($admin->createToken('t')->plainTextToken)->post('/api/v1/sunat/certificate', [
        'certificate' => UploadedFile::fake()->createWithContent('c.pfx', $certificate['pfx']),
        'password' => $certPassword,
    ], ['Accept' => 'application/json'])->assertOk();
    $responses[] = $upload->getContent();
    $call($admin, 'POST', '/api/v1/sunat/validate')->assertOk();
    $call($admin, 'POST', '/api/v1/series', ['document_type' => '03', 'code' => 'B001'])->assertCreated();
    $call($admin, 'GET', '/api/v1/sunat/settings')->assertOk();
    $call($seller, 'GET', '/api/v1/sunat/status')->assertOk();
    $call($seller, 'GET', '/api/v1/series')->assertOk();
    $call($admin, 'GET', '/api/v1/audit-logs')->assertOk();

    $database = collect(DB::connection()->getSchemaBuilder()->getTableListing())
        ->map(fn (string $table) => json_encode(DB::table($table)->get()))
        ->implode("\n");

    $haystacks = ['respuestas' => implode("\n", $responses), 'logs' => implode("\n", $logged), 'base de datos' => $database];
    $secrets = ['clave SOL' => $solPassword, 'contraseña del certificado' => $certPassword, 'clave privada' => $keyFragment];

    foreach ($haystacks as $where => $haystack) {
        foreach ($secrets as $name => $secret) {
            expect(str_contains($haystack, $secret))->toBeFalse("La {$name} aparece en {$where}");
        }
    }
});

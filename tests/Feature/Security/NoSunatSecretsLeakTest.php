<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatSender;
use App\Tenancy\TenantContext;
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
 *
 * Spec 005 (T071): el recorrido sigue con la emisión, un envío fallido, el
 * reintento y las descargas; el XML firmado tampoco lleva la clave privada.
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

    // Emisión (spec 005): SUNAT no responde, luego acepta al reintentar.
    app()->instance(SunatSender::class, new FakeSunatSender(FakeSunatSender::unreachable(), FakeSunatSender::accepted()));
    $product = app(TenantContext::class)->run($company, function () use ($company) {
        $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10.00']);
        ProductLot::factory()->for($product)->quantity('5')->create();

        return $product;
    });
    $id = $call($seller, 'POST', '/api/v1/sales-documents', [
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
        'lines' => [['product_id' => $product->id, 'quantity' => '1']],
    ])->assertCreated()->json('data.id');
    $call($seller, 'POST', "/api/v1/sales-documents/{$id}/retry")->assertOk();
    // Spec 007: una nota de crédito sobre el comprobante, con su XML firmado.
    $call($admin, 'POST', '/api/v1/series', ['document_type' => '07', 'code' => 'BC01'])->assertCreated();
    $noteId = $call($seller, 'POST', "/api/v1/sales-documents/{$id}/credit-notes", [
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'reason_code' => '01', 'reason' => 'Anulación de prueba',
    ])->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $responses[] = test()->withToken($seller->createToken('t')->plainTextToken)->get("/api/v1/sales-documents/{$noteId}/xml")->assertOk()->getContent();
    $call($seller, 'GET', '/api/v1/sales-documents')->assertOk();
    $call($seller, 'GET', "/api/v1/sales-documents/{$id}")->assertOk();
    foreach (['xml', 'cdr', 'pdf?format=80mm'] as $download) {
        app('auth')->forgetGuards();
        $responses[] = test()->withToken($seller->createToken('t')->plainTextToken)->get("/api/v1/sales-documents/{$id}/{$download}")->assertOk()->getContent();
    }

    $call($admin, 'GET', '/api/v1/audit-logs')->assertOk();

    $database = collect(DB::connection()->getSchemaBuilder()->getTableListing())
        ->map(fn (string $table) => json_encode(DB::table($table)->get()))
        ->implode("\n");

    $haystacks = ['respuestas' => implode("\n", $responses), 'logs' => implode("\n", $logged), 'base de datos' => $database];
    $secrets = ['clave SOL' => $solPassword, 'contraseña del certificado' => $certPassword, 'clave privada' => $keyFragment, 'cabecera de clave privada' => 'PRIVATE KEY'];

    foreach ($haystacks as $where => $haystack) {
        foreach ($secrets as $name => $secret) {
            expect(str_contains($haystack, $secret))->toBeFalse("La {$name} aparece en {$where}");
        }
    }
});

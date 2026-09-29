<?php

use App\Enums\SunatEnvironment;
use App\Enums\SunatStatus;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\SunatSetting;
use Illuminate\Support\Facades\DB;

/*
 * T012 · Los secretos se guardan cifrados y nunca se serializan (RF-001/002).
 */

it('guarda la clave SOL, el PEM y la contraseña cifrados en la base de datos', function () {
    $company = Company::factory()->create();
    SunatSetting::create([
        'company_id' => $company->id, 'environment' => SunatEnvironment::Beta, 'status' => SunatStatus::Pending,
        'sol_user' => 'USUARIO1', 'sol_password' => 'Clave-Sol-Secreta-9',
    ]);
    Certificate::factory()->create(['company_id' => $company->id, 'pem' => 'PEM-SECRETO-XYZ', 'password' => 'Pass-Cert-Secreta-7']);

    $raw = json_encode([DB::table('sunat_settings')->get(), DB::table('certificates')->get()]);

    expect($raw)->not->toContain('Clave-Sol-Secreta-9')
        ->not->toContain('PEM-SECRETO-XYZ')
        ->not->toContain('Pass-Cert-Secreta-7')
        ->and(SunatSetting::withoutTenancy()->first()->sol_password)->toBe('Clave-Sol-Secreta-9');
});

it('no serializa los secretos', function () {
    $certificate = Certificate::factory()->create(['pem' => 'PEM-SECRETO-XYZ', 'password' => 'Pass-Cert-Secreta-7']);

    expect(json_encode($certificate->toArray()))->not->toContain('PEM-SECRETO-XYZ')->not->toContain('Pass-Cert-Secreta-7');
});

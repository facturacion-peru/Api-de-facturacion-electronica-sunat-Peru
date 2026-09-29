<?php

use App\Enums\CertificateStatus;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\TestCertificates;

/*
 * T022 · HU-1.1 a HU-1.4: subir el certificado, validado y protegido.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create(['ruc' => '20131312955']);
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create(['name' => 'Ana']);
    $this->seller = User::factory()->forCompany($this->company, CompanyRole::Seller)->create();
    $this->upload = function (string $contents, ?string $password, string $name = 'certificado.pfx', ?User $as = null) {
        app('auth')->forgetGuards();
        $file = UploadedFile::fake()->createWithContent($name, $contents);

        return $this->withToken(($as ?? $this->admin)->createToken('t')->plainTextToken)
            ->post('/api/v1/sunat/certificate', ['certificate' => $file, 'password' => $password], ['Accept' => 'application/json']);
    };
});

it('HU-1.1 guarda el certificado cifrado, expone solo metadatos y deja pendiente', function () {
    $response = ($this->upload)(TestCertificates::make()['pfx'], 'clave-cert-123')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.certificate.ruc', '20131312955')
        ->assertJsonPath('data.certificate.uploaded_by', 'Ana');

    expect($response->json('data.certificate.days_to_expire'))->toBeGreaterThan(360)
        ->and($response->getContent())->not->toContain('PRIVATE KEY')->not->toContain('clave-cert-123')
        ->and(Certificate::withoutTenancy()->sole()->pem)->toContain('PRIVATE KEY')
        ->and(AuditLog::withoutTenancy()->where('action', 'sunat.certificate_uploaded')->count())->toBe(1);
});

it('acepta un .pem con clave privada', function () {
    ($this->upload)(TestCertificates::make()['pem'], null, 'certificado.pem')->assertOk();
});

it('HU-1.2 rechaza contraseña incorrecta o archivo dañado con el motivo exacto', function () {
    ($this->upload)(TestCertificates::make()['pfx'], 'otra')
        ->assertUnprocessable()
        ->assertJsonPath('errors.certificate.0', 'La contraseña del certificado es incorrecta.');

    ($this->upload)('basura', 'x')
        ->assertUnprocessable()
        ->assertJsonPath('errors.certificate.0', 'El archivo no es un certificado válido o está dañado.');

    expect(Certificate::withoutTenancy()->count())->toBe(0);
});

it('HU-1.3 rechaza un certificado de otro RUC', function () {
    ($this->upload)(TestCertificates::make(ruc: '20100070970')['pfx'], 'clave-cert-123')
        ->assertUnprocessable()
        ->assertJsonPath('errors.certificate.0', 'El certificado es del RUC 20100070970, no del de la empresa (20131312955).');
});

it('rechaza otros tipos de archivo', function () {
    ($this->upload)('x', null, 'foto.jpg')->assertUnprocessable()->assertJsonValidationErrors(['certificate']);
});

it('reemplazar el certificado conserva el historial', function () {
    ($this->upload)(TestCertificates::make()['pfx'], 'clave-cert-123')->assertOk();
    $response = ($this->upload)(TestCertificates::make()['pfx'], 'clave-cert-123')->assertOk();

    expect(Certificate::withoutTenancy()->where('status', CertificateStatus::Current)->count())->toBe(1)
        ->and(Certificate::withoutTenancy()->where('status', CertificateStatus::Replaced)->whereNotNull('replaced_at')->count())->toBe(1)
        ->and($response->json('data.certificates_history'))->toHaveCount(2)
        ->and(AuditLog::withoutTenancy()->where('action', 'sunat.certificate_replaced')->count())->toBe(1);
});

it('HU-1.5 el vendedor no sube certificados', function () {
    ($this->upload)(TestCertificates::make()['pfx'], 'clave-cert-123', 'c.pfx', $this->seller)->assertForbidden();
});

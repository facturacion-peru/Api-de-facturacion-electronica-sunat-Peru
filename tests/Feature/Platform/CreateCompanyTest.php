<?php

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Database\Factories\EstablishmentFactory;
use Illuminate\Support\Facades\Notification;

/*
 * T030 · HU-1 Alta de empresa por el administrador de la plataforma
 * (RF-001 a RF-004, A-03).
 */

beforeEach(function () {
    EstablishmentFactory::ensureLimaUbigeo();
    Notification::fake();

    $this->admin = User::factory()->platformAdmin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->payload = [
        'ruc' => '20131312955',
        'razon_social' => 'Bodega Ana S.A.C.',
        'nombre_comercial' => 'Bodega Ana',
        'tax_regime' => 'rmt',
        'email' => 'contacto@bodega-ana.pe',
        'phone' => '987654321',
        'address' => 'Av. Arequipa 123',
        'ubigeo' => '150101',
        'admin_email' => 'Ana@Bodega-Ana.pe',
    ];
});

function createCompany(array $payload, ?string $token)
{
    return test()->withToken($token ?? '')->postJson('/api/v1/platform/companies', $payload);
}

it('HU-1.1 crea la empresa, su establecimiento principal y la invitación del administrador', function () {
    $response = createCompany($this->payload, $this->token);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.ruc', '20131312955')
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.fiscal_address.ubigeo', '150101');

    $company = Company::where('ruc', '20131312955')->firstOrFail();
    $main = Establishment::withoutTenancy()->where('company_id', $company->id)->sole();
    $invitation = Invitation::withoutTenancy()->where('company_id', $company->id)->sole();

    expect($main->code)->toBe('0000')
        ->and($main->is_main)->toBeTrue()
        ->and($main->address)->toBe('Av. Arequipa 123')
        ->and($invitation->email)->toBe('ana@bodega-ana.pe')
        ->and($invitation->role)->toBe(CompanyRole::CompanyAdmin)
        ->and($invitation->invited_by)->toBe($this->admin->id)
        ->and($invitation->expires_at->between(now()->addHours(71), now()->addHours(73)))->toBeTrue();

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        function (InvitationNotification $notification, array $channels, object $notifiable) use ($invitation) {
            return $notifiable->routes['mail'] === 'ana@bodega-ana.pe'
                && Invitation::hashToken($notification->token) === $invitation->token_hash
                && str_contains($notification->url(), '/invitacion/'.$notification->token);
        }
    );

    expect(AuditLog::withoutTenancy()->where('action', 'company.created')->where('company_id', $company->id)->count())->toBe(1);
});

it('HU-1.2 rechaza un RUC ya registrado', function () {
    Company::factory()->create(['ruc' => '20131312955']);

    createCompany($this->payload, $this->token)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ruc']);

    expect(Company::count())->toBe(1);
});

it('HU-1.3 rechaza un RUC inválido explicando el motivo', function () {
    createCompany([...$this->payload, 'ruc' => '20131312956'], $this->token)
        ->assertUnprocessable()
        ->assertJsonPath('errors.ruc.0', 'El RUC no es válido: el dígito verificador no coincide.');
});

it('HU-1.4 deduce el tipo de persona del RUC', function (string $ruc, string $personType) {
    createCompany([...$this->payload, 'ruc' => $ruc], $this->token)
        ->assertCreated()
        ->assertJsonPath('data.person_type', $personType);
})->with([
    'persona natural' => ['10468536248', 'natural'],
    'persona jurídica' => ['20131312955', 'juridica'],
]);

it('ignora un tipo de persona enviado por el cliente', function () {
    createCompany([...$this->payload, 'person_type' => 'natural'], $this->token)
        ->assertCreated()
        ->assertJsonPath('data.person_type', 'juridica');
});

it('exige los campos obligatorios', function () {
    createCompany([], $this->token)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ruc', 'razon_social', 'tax_regime', 'email', 'address', 'ubigeo', 'admin_email']);
});

it('valida el régimen tributario y el ubigeo', function () {
    createCompany([...$this->payload, 'tax_regime' => 'otro', 'ubigeo' => '999999'], $this->token)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_regime', 'ubigeo']);
});

it('rechaza como administrador un correo que ya tiene cuenta', function () {
    User::factory()->forCompany(Company::factory()->create())->create(['email' => 'ana@bodega-ana.pe']);

    createCompany($this->payload, $this->token)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['admin_email']);

    expect(Company::where('ruc', '20131312955')->exists())->toBeFalse();
});

it('rechaza a un administrador de empresa', function () {
    $company = Company::factory()->withMainEstablishment()->create();
    $companyAdmin = User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create();

    createCompany($this->payload, $companyAdmin->createToken('t')->plainTextToken)->assertForbidden();
});

it('rechaza una petición sin sesión', function () {
    createCompany($this->payload, null)->assertUnauthorized();
});

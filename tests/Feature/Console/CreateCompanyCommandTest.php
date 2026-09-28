<?php

use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use Database\Factories\EstablishmentFactory;
use Illuminate\Support\Facades\Notification;

/*
 * T032 · company:create: alta de empresa por consola mientras no exista la
 * interfaz de la plataforma (spec 006). Cumple CE-001 (< 3 minutos).
 */

beforeEach(function () {
    EstablishmentFactory::ensureLimaUbigeo();
    Notification::fake();
    User::factory()->platformAdmin()->create(['email' => 'plataforma@example.com']);
});

function answerCompanyQuestions($command, array $overrides = [])
{
    $a = [
        'actor' => 'plataforma@example.com',
        'ruc' => '20131312955',
        'razon_social' => 'Bodega Ana S.A.C.',
        'nombre_comercial' => 'Bodega Ana',
        'tax_regime' => 'rmt',
        'email' => 'contacto@bodega-ana.pe',
        'phone' => '987654321',
        'address' => 'Av. Arequipa 123',
        'ubigeo' => '150101',
        'admin_email' => 'ana@bodega-ana.pe',
        ...$overrides,
    ];

    return $command
        ->expectsQuestion('Tu correo de administrador de la plataforma', $a['actor'])
        ->expectsQuestion('RUC', $a['ruc'])
        ->expectsQuestion('Razón social', $a['razon_social'])
        ->expectsQuestion('Nombre comercial (opcional)', $a['nombre_comercial'])
        ->expectsChoice('Régimen tributario', $a['tax_regime'], ['nrus', 'rer', 'rmt', 'general'])
        ->expectsQuestion('Correo de contacto de la empresa', $a['email'])
        ->expectsQuestion('Teléfono (opcional)', $a['phone'])
        ->expectsQuestion('Dirección fiscal', $a['address'])
        ->expectsQuestion('Ubigeo del distrito (6 dígitos)', $a['ubigeo'])
        ->expectsQuestion('Correo del administrador de la empresa', $a['admin_email']);
}

it('crea la empresa y muestra el enlace de invitación', function () {
    answerCompanyQuestions($this->artisan('company:create'))
        ->expectsOutputToContain('Empresa creada: Bodega Ana S.A.C. (20131312955)')
        ->expectsOutputToContain(config('app.frontend_url').'/invitacion/')
        ->assertSuccessful();

    $company = Company::where('ruc', '20131312955')->firstOrFail();

    expect(Invitation::withoutTenancy()->where('company_id', $company->id)->value('email'))->toBe('ana@bodega-ana.pe');
});

it('rechaza a quien no es administrador de la plataforma', function () {
    User::factory()->create(['email' => 'otro@example.com']);

    $this->artisan('company:create')
        ->expectsQuestion('Tu correo de administrador de la plataforma', 'otro@example.com')
        ->expectsOutputToContain('no corresponde a un administrador de la plataforma')
        ->assertFailed();
});

it('muestra los errores de validación y no crea nada', function () {
    answerCompanyQuestions($this->artisan('company:create'), ['ruc' => '20131312956'])
        ->expectsOutputToContain('dígito verificador')
        ->assertFailed();

    expect(Company::count())->toBe(0);
});

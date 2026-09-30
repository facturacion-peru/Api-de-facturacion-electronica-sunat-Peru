<?php

use App\Enums\SalesDocumentStatus;
use App\Enums\SunatStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\SalesDocument;
use App\Models\SunatSetting;
use App\Models\UbiDistrito;
use App\Models\User;
use App\Services\SunatConfigService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\Support\SalesFixture;

/*
 * T031 · HU-3 Corregir datos legales, incluido el domicilio fiscal y el RUC.
 */

beforeEach(function () {
    ['company' => $this->company, 'admin' => $this->admin, 'receipt' => $receipt] = SalesFixture::issuable();
    $this->document = SalesDocument::factory()->status(SalesDocumentStatus::Accepted)->create(['series_id' => $receipt->id, 'issuer_ruc' => $this->company->ruc]);
    app(TenantContext::class)->clear();

    $this->root = User::factory()->platformAdmin()->create();
    $this->patch = function (array $data) {
        app('auth')->forgetGuards();

        return $this->withToken($this->root->createToken('t')->plainTextToken)->patchJson("/api/v1/platform/companies/{$this->company->id}", $data);
    };
    $this->setting = fn () => SunatSetting::withoutTenancy()->where('company_id', $this->company->id)->first();
});

it('corrige el domicilio fiscal y lo audita', function () {
    UbiDistrito::firstOrCreate(['id' => '150122'], ['nombre' => 'Miraflores', 'info_busqueda' => 'Lima / Lima / Miraflores', 'provincia_id' => '150100', 'region_id' => '150000']);

    ($this->patch)(['fiscal_address' => ['address' => 'Av. Larco 1150', 'ubigeo' => '150122']])
        ->assertOk()->assertJsonPath('data.fiscal_address.address', 'Av. Larco 1150')->assertJsonPath('data.fiscal_address.ubigeo', '150122');

    $main = Establishment::withoutTenancy()->where('company_id', $this->company->id)->where('is_main', true)->first();
    $log = AuditLog::withoutTenancy()->where('action', 'company.updated')->latest('id')->first();
    expect($main->address)->toBe('Av. Larco 1150')
        ->and($log->changes['fiscal_address.address']['to'])->toBe('Av. Larco 1150')
        ->and($log->actor_id)->toBe($this->root->id);
});

it('rechaza un ubigeo que no existe', function () {
    ($this->patch)(['fiscal_address' => ['address' => 'Av. X', 'ubigeo' => '999999']])->assertStatus(422)->assertJsonValidationErrors(['fiscal_address.ubigeo']);
});

it('cambiar el RUC devuelve la configuración SUNAT a pendiente, con el motivo', function () {
    ($this->patch)(['ruc' => '20100070970'])->assertOk()->assertJsonPath('data.sunat_status', 'pending');

    expect(($this->setting)()->status)->toBe(SunatStatus::Pending)
        ->and(($this->setting)()->last_validation_error)->toBe(SunatConfigService::RUC_CHANGED);
});

it('los comprobantes emitidos conservan el RUC con que se emitieron', function () {
    $old = $this->company->ruc;
    ($this->patch)(['ruc' => '20100070970'])->assertOk();

    expect(SalesDocument::withoutTenancy()->find($this->document->id)->issuer_ruc)->toBe($old);
});

it('no admite el RUC de otra empresa', function () {
    $other = Company::factory()->create();

    ($this->patch)(['ruc' => $other->ruc])->assertStatus(422)->assertJsonValidationErrors(['ruc']);
});

it('validar con un certificado de otro RUC deja la configuración en error', function () {
    Http::fake(['*' => Http::response('<wsdl:definitions/>', 200)]);
    ($this->patch)(['ruc' => '20100070970'])->assertOk();

    $company = $this->company->fresh();
    app(TenantContext::class)->run($company, fn () => app(SunatConfigService::class)->validate($company, $this->admin));

    expect(($this->setting)()->status)->toBe(SunatStatus::Error)
        ->and(($this->setting)()->last_validation_error)->toContain('sube uno del RUC actual');
});

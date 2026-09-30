<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Enums\TaxRegime;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use App\Rules\Ruc;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanyService
{
    public function __construct(
        private AuditLogger $audit,
        private InvitationService $invitations,
        private SunatConfigService $sunat,
        private TenantContext $tenant,
    ) {}

    /**
     * Alta de empresa (HU-1): empresa, establecimiento principal e invitación
     * de su administrador, en una sola transacción.
     *
     * @param  array{ruc: string, razon_social: string, nombre_comercial?: ?string, tax_regime: string, email: string, phone?: ?string, address: string, ubigeo: string, admin_email: string}  $data
     */
    public function create(array $data, User $actor): CreatedCompany
    {
        return DB::transaction(function () use ($data, $actor) {
            $company = Company::create([
                'ruc' => $data['ruc'],
                'razon_social' => $data['razon_social'],
                'nombre_comercial' => $data['nombre_comercial'] ?? null,
                'person_type' => Ruc::personType($data['ruc']),
                'tax_regime' => TaxRegime::from($data['tax_regime']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'active' => true,
            ]);

            Establishment::create([
                'company_id' => $company->id,
                'code' => Establishment::MAIN_CODE,
                'name' => 'Domicilio fiscal',
                'address' => $data['address'],
                'ubigeo' => $data['ubigeo'],
                'is_main' => true,
            ]);

            $this->audit->record('company.created', $company, [
                'ruc' => $company->ruc,
                'razon_social' => $company->razon_social,
            ], actor: $actor);

            $invitation = $this->invitations->issue($company, $data['admin_email'], CompanyRole::CompanyAdmin, $actor);

            return new CreatedCompany($company->load('mainEstablishment.district'), $invitation);
        });
    }

    /**
     * Actualiza datos de la empresa y audita cada cambio con su valor anterior
     * y nuevo. Qué campos se admiten lo decide la request: la empresa edita
     * solo contacto (HU-5.1); la plataforma también RUC, razón social y
     * régimen (HU-5.2). Si cambia el RUC se recalcula el tipo de persona.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data, User $actor): Company
    {
        $fiscalAddress = $data['fiscal_address'] ?? null;
        unset($data['fiscal_address']);

        $company->fill($data);
        $rucChanged = $company->isDirty('ruc');

        if ($rucChanged) {
            $company->person_type = Ruc::personType($company->ruc);
        }

        $changes = AuditLogger::diff($company);

        DB::transaction(function () use ($company, $fiscalAddress, $rucChanged, &$changes) {
            $company->save();

            if ($fiscalAddress !== null) {
                $changes += $this->updateFiscalAddress($company, $fiscalAddress);
            }

            // El certificado y la validación eran del RUC anterior (spec 006, HU-3).
            if ($rucChanged) {
                $this->tenant->run($company, fn () => $this->sunat->rucChanged($company));
            }
        });

        if ($changes !== []) {
            $this->audit->record('company.updated', $company, $changes, actor: $actor);
        }

        return $company;
    }

    /**
     * @param  array{address: string, ubigeo: string}  $address
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function updateFiscalAddress(Company $company, array $address): array
    {
        $main = Establishment::withoutTenancy()->where('company_id', $company->id)->where('is_main', true)->firstOrFail();
        $main->fill($address);
        $changes = collect(AuditLogger::diff($main))->mapWithKeys(fn ($change, $field) => ["fiscal_address.{$field}" => $change])->all();

        if ($changes !== []) {
            $this->tenant->run($company, fn () => $main->save());
        }

        return $changes;
    }

    /** Guarda el logo en el disco público y borra el anterior. */
    public function updateLogo(Company $company, UploadedFile $logo, User $actor): Company
    {
        $previous = $company->logo_path;
        $path = $logo->store("companies/{$company->id}", 'public');

        $company->update(['logo_path' => $path]);

        if ($previous !== null && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        $this->audit->record('company.logo_updated', $company, actor: $actor);

        return $company;
    }

    /**
     * Activa o desactiva la empresa (HU-7). Al desactivar se revocan las
     * sesiones de todos sus usuarios; los datos se conservan.
     */
    public function setActive(Company $company, bool $active, User $actor): Company
    {
        if ($company->active === $active) {
            return $company;
        }

        DB::transaction(function () use ($company, $active, $actor) {
            $company->update(['active' => $active]);

            if (! $active) {
                User::whereIn('id', $company->memberships()->select('user_id'))
                    ->each(fn (User $user) => $user->tokens()->delete());
            }

            $this->audit->record($active ? 'company.activated' : 'company.deactivated', $company, actor: $actor);
        });

        return $company;
    }
}

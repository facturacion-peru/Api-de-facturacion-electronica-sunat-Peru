<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Enums\CompanyRole;
use App\Enums\TaxRegime;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use App\Rules\Ruc;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function __construct(
        private AuditLogger $audit,
        private InvitationService $invitations,
    ) {}

    /**
     * Alta de empresa (HU-1): empresa, establecimiento principal e invitación
     * de su administrador, en una sola transacción.
     *
     * @param  array{ruc: string, razon_social: string, nombre_comercial?: ?string, tax_regime: string, email: string, phone?: ?string, address: string, ubigeo: string, admin_email: string}  $data
     */
    public function create(array $data, User $actor): Company
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

            $this->invitations->issue($company, $data['admin_email'], CompanyRole::CompanyAdmin, $actor);

            return $company->load('mainEstablishment.district');
        });
    }
}

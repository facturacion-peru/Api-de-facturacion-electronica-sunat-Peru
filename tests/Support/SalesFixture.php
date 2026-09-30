<?php

namespace Tests\Support;

use App\Enums\CompanyRole;
use App\Enums\DocumentType;
use App\Enums\SunatStatus;
use App\Enums\TaxRegime;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\Series;
use App\Models\SunatSetting;
use App\Models\User;

/**
 * Empresa lista para emitir (spec 005): configuración SUNAT validada,
 * certificado vigente con un PEM real y series B001 y F001 activas.
 */
final class SalesFixture
{
    /** @return array{company: Company, admin: User, seller: User, receipt: Series, invoice: Series, receiptNote: Series, invoiceNote: Series} */
    public static function issuable(TaxRegime $regime = TaxRegime::Rmt): array
    {
        $company = Company::factory()->withMainEstablishment()->create(['tax_regime' => $regime]);

        SunatSetting::create([
            'company_id' => $company->id, 'environment' => 'beta', 'status' => SunatStatus::Validated,
            'sol_user' => 'VENTAS01', 'sol_password' => 'clave', 'last_validated_at' => now(),
        ]);
        Certificate::factory()->create(['company_id' => $company->id, 'pem' => TestCertificates::signingPem()]);

        return [
            'company' => $company,
            'admin' => User::factory()->forCompany($company, CompanyRole::CompanyAdmin)->create(),
            'seller' => User::factory()->forCompany($company, CompanyRole::Seller)->create(['name' => 'Luis']),
            'receipt' => Series::factory()->create(['company_id' => $company->id, 'code' => 'B001', 'last_number' => 150]),
            'invoice' => Series::factory()->create(['company_id' => $company->id, 'code' => 'F001', 'document_type' => DocumentType::Invoice]),
            // Series de nota de crédito (spec 007).
            'receiptNote' => Series::factory()->create(['company_id' => $company->id, 'code' => 'BC01', 'document_type' => DocumentType::CreditNote]),
            'invoiceNote' => Series::factory()->create(['company_id' => $company->id, 'code' => 'FC01', 'document_type' => DocumentType::CreditNote]),
        ];
    }
}

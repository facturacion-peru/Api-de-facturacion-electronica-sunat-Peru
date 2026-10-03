<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Enums\CustomerDocumentType;
use App\Enums\DocumentType;
use App\Enums\PersonType;
use App\Enums\TaxRegime;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\Ruc;
use App\Services\InventoryService;
use App\Services\SalesDocumentService;
use App\Services\SeriesService;
use App\Services\SunatConfigService;
use App\Services\TicketService;
use App\Tenancy\TenantContext;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Cuentas de prueba para desarrollo. Solo en APP_ENV=local: permite hacer
 * `php artisan migrate:fresh --seed` sin perder las cuentas de trabajo.
 *
 * Todas usan la misma contraseña (PASSWORD) y el dominio reservado .test.
 * Es idempotente: se puede ejecutar varias veces.
 */
class DevelopmentSeeder extends Seeder
{
    public const PASSWORD = 'Clave-demo-123';

    public const PLATFORM_ADMIN = 'plataforma@demo.test';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DevelopmentSeeder: omitido (APP_ENV no es local).');

            return;
        }

        EstablishmentFactory::ensureLimaUbigeo();

        $this->user('Administrador Plataforma', self::PLATFORM_ADMIN, platformAdmin: true);

        $demo = $this->company('2060000001', 'Empresa Demo S.A.C.', 'Bodega Demo', 'contacto@demo.test');
        $demoAdmin = $this->member($demo, 'Ana Administradora', 'admin@demo.test', CompanyRole::CompanyAdmin);
        $demoSeller = $this->member($demo, 'Luis Vendedor', 'vendedor@demo.test', CompanyRole::Seller);
        $this->member($demo, 'Carla Desactivada', 'inactivo@demo.test', CompanyRole::Seller, active: false);

        // Segunda empresa para comprobar el aislamiento entre empresas.
        $otra = $this->company('2060000002', 'Otra Empresa S.A.C.', 'Otra Tienda', 'contacto@otra.test');
        $otraAdmin = $this->member($otra, 'Olga Administradora', 'admin@otra.test', CompanyRole::CompanyAdmin);

        $this->products($demo, $demoAdmin, [
            ['ARZ-001', 'Arroz extra 5 kg', 'good', 'NIU', '25.90', '10', '5', false, [['24', 18.5, null], ['12', 19.0, null]]],
            ['YOG-001', 'Yogur de fresa 1 L', 'good', 'NIU', '7.50', '10', '6', true, [['10', 4.2, 10], ['15', 4.3, 60]]],
            ['AZU-001', 'Azúcar rubia', 'good', 'KGM', '4.20', '20', '10', false, [['50.500', 3.1, null]]],
            ['LEC-001', 'Leche evaporada 400 g', 'good', 'NIU', '4.50', '10', '12', false, [['8', 3.4, null]]],
            ['DEL-001', 'Delivery en el distrito', 'service', 'ZZ', '5.00', '10', null, false, []],
            ...self::BODEGA,
        ]);
        $this->products($otra, $otraAdmin, [
            ['OTR-001', 'Producto de otra empresa', 'good', 'NIU', '10.00', '10', null, false, [['5', 6.0, null]]],
        ]);

        $this->tickets($demo, $demoAdmin, $demoSeller);
        $this->sunat($demo, $demoAdmin);
        $this->salesDocuments($demo, $demoSeller);

        $this->command?->info('Usuarios de prueba listos (contraseña: '.self::PASSWORD.').');
    }

    private function user(string $name, string $email, bool $platformAdmin = false): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->fill(['name' => $name, 'password' => self::PASSWORD, 'active' => true]);
        $user->is_platform_admin = $platformAdmin;
        $user->save();

        return $user;
    }

    /** RUC ficticio con dígito verificador válido a partir de 10 dígitos. */
    private function company(string $base, string $razonSocial, string $nombreComercial, string $email): Company
    {
        $ruc = $base.Ruc::checkDigit($base);

        $company = Company::updateOrCreate(['ruc' => $ruc], [
            'razon_social' => $razonSocial,
            'nombre_comercial' => $nombreComercial,
            'person_type' => PersonType::Juridica,
            'tax_regime' => TaxRegime::Rmt,
            'email' => $email,
            'active' => true,
        ]);

        Establishment::withoutTenancy()->updateOrCreate(
            ['company_id' => $company->id, 'code' => Establishment::MAIN_CODE],
            ['name' => 'Domicilio fiscal', 'address' => 'Av. Demo 123', 'ubigeo' => EstablishmentFactory::LIMA, 'is_main' => true],
        );

        return $company;
    }

    private function member(Company $company, string $name, string $email, CompanyRole $role, bool $active = true): User
    {
        $user = $this->user($name, $email);

        CompanyMembership::withoutTenancy()->updateOrCreate(
            ['user_id' => $user->id],
            ['company_id' => $company->id, 'role' => $role, 'active' => $active],
        );

        return $user;
    }

    /**
     * Surtido de bodega para ver la paginación del catálogo de «Vender»
     * (spec 012, T015): más de dos páginas de 20. Incluye exonerados (20),
     * perecibles con vencimiento, servicios y uno sin stock («Sin disponible»).
     *
     * @var list<array{string, string, string, string, string, string, ?string, bool, list<array{string, float, ?int}>}>
     */
    private const BODEGA = [
        ['ACE-001', 'Aceite vegetal 1 L', 'good', 'NIU', '9.80', '10', null, false, [['24', 7.9, null]]],
        ['FID-001', 'Fideos spaghetti 500 g', 'good', 'NIU', '3.20', '10', null, false, [['40', 2.3, null]]],
        ['ATN-001', 'Atún en aceite 170 g', 'good', 'NIU', '6.50', '10', null, false, [['30', 4.8, null]]],
        ['GAL-001', 'Galletas de soda x 6', 'good', 'NIU', '2.80', '10', null, false, [['50', 1.9, null]]],
        ['GAL-002', 'Galletas de chocolate', 'good', 'NIU', '1.20', '10', null, false, [['80', 0.8, null]]],
        ['CHO-001', 'Chocolate en barra', 'good', 'NIU', '1.50', '10', null, false, [['60', 1.0, null]]],
        ['GAS-001', 'Gaseosa 500 ml', 'good', 'NIU', '2.50', '10', null, false, [['48', 1.6, null]]],
        ['GAS-002', 'Gaseosa 1.5 L', 'good', 'NIU', '6.00', '10', null, false, [['24', 4.2, null]]],
        ['AGU-001', 'Agua sin gas 625 ml', 'good', 'NIU', '1.50', '10', null, false, [['60', 0.9, null]]],
        ['CER-001', 'Cerveza en lata 355 ml', 'good', 'NIU', '4.50', '10', null, false, [['36', 3.3, null]]],
        ['CAF-001', 'Café instantáneo 50 g', 'good', 'NIU', '8.50', '10', null, false, [['15', 6.4, null]]],
        ['AVE-001', 'Avena 900 g', 'good', 'NIU', '6.80', '10', null, false, [['20', 5.0, null]]],
        ['LEN-001', 'Lentejas 500 g', 'good', 'NIU', '4.60', '10', null, false, [['25', 3.4, null]]],
        ['FRE-001', 'Frejol canario 500 g', 'good', 'NIU', '6.20', '10', null, false, [['25', 4.6, null]]],
        ['SAL-001', 'Sal de mesa 1 kg', 'good', 'NIU', '1.80', '10', null, false, [['30', 1.1, null]]],
        ['VIN-001', 'Vinagre blanco 500 ml', 'good', 'NIU', '2.90', '10', null, false, [['18', 1.9, null]]],
        ['MAY-001', 'Mayonesa 400 g', 'good', 'NIU', '9.40', '10', null, false, [['12', 7.1, null]]],
        ['KET-001', 'Kétchup 400 g', 'good', 'NIU', '7.80', '10', null, false, [['12', 5.8, null]]],
        ['MOS-001', 'Mostaza 200 g', 'good', 'NIU', '4.90', '10', null, false, []],
        ['SOP-001', 'Sopa instantánea', 'good', 'NIU', '1.80', '10', null, false, [['40', 1.2, null]]],
        ['CAR-001', 'Caramelos x 100', 'good', 'NIU', '9.00', '10', null, false, [['10', 6.5, null]]],
        ['DET-001', 'Detergente 900 g', 'good', 'NIU', '11.90', '10', null, false, [['12', 9.2, null]]],
        ['JAB-001', 'Jabón de tocador', 'good', 'NIU', '3.20', '10', null, false, [['30', 2.2, null]]],
        ['PHI-001', 'Papel higiénico x 4', 'good', 'NIU', '6.90', '10', null, false, [['20', 5.1, null]]],
        ['SHA-001', 'Champú 400 ml', 'good', 'NIU', '14.50', '10', null, false, [['10', 11.0, null]]],
        ['PAS-001', 'Pasta dental 90 g', 'good', 'NIU', '5.40', '10', null, false, [['18', 3.9, null]]],
        ['VEL-001', 'Velas x 6', 'good', 'NIU', '4.50', '10', null, false, [['15', 3.0, null]]],
        ['FOS-001', 'Fósforos x 10', 'good', 'NIU', '2.00', '10', null, false, [['25', 1.3, null]]],
        ['PIL-001', 'Pilas AA x 2', 'good', 'NIU', '5.50', '10', null, false, [['20', 3.8, null]]],
        ['HUE-001', 'Huevos', 'good', 'KGM', '8.90', '20', null, false, [['15.000', 7.2, null]]],
        ['PLA-001', 'Plátano de seda', 'good', 'KGM', '3.50', '20', null, false, [['12.000', 2.4, null]]],
        ['PAP-001', 'Papa amarilla', 'good', 'KGM', '4.80', '20', null, false, [['25.000', 3.5, null]]],
        ['CEB-001', 'Cebolla roja', 'good', 'KGM', '3.20', '20', null, false, [['18.000', 2.2, null]]],
        ['TOM-001', 'Tomate', 'good', 'KGM', '4.00', '20', null, false, [['10.000', 2.8, null]]],
        ['LIM-001', 'Limón', 'good', 'KGM', '5.50', '20', null, false, [['8.000', 3.9, null]]],
        ['POL-001', 'Pollo entero', 'good', 'KGM', '10.90', '10', null, true, [['20.000', 8.4, 5]]],
        ['QUE-001', 'Queso fresco 500 g', 'good', 'NIU', '12.50', '10', null, true, [['10', 9.5, 7]]],
        ['MAN-001', 'Mantequilla 200 g', 'good', 'NIU', '7.90', '10', null, true, [['12', 5.9, 30]]],
        ['PAN-001', 'Pan de molde', 'good', 'NIU', '7.20', '10', null, true, [['10', 5.3, 6]]],
        ['REC-001', 'Recarga de celular', 'service', 'ZZ', '10.00', '10', null, false, []],
        ['IMP-001', 'Impresión por hoja', 'service', 'ZZ', '0.50', '10', null, false, []],
    ];

    /**
     * Productos demo con entradas por el servicio real (lotes, movimientos y
     * auditoría). Solo se cargan si el producto aún no existe.
     *
     * @param  list<array{string, string, string, string, string, string, ?string, bool, list<array{string, float, ?int}>}>  $rows
     *                                                                                                                              código, nombre, tipo, unidad, precio, afectación, mínimo, controla vencimiento, [cantidad, costo, días para vencer]
     */
    private function products(Company $company, User $admin, array $rows): void
    {
        app(TenantContext::class)->run($company, function () use ($company, $admin, $rows) {
            foreach ($rows as [$code, $name, $type, $unit, $price, $igv, $min, $tracksExpiry, $entries]) {
                if (Product::where('code', $code)->exists()) {
                    continue;
                }

                $product = Product::create([
                    'company_id' => $company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'unit' => $unit,
                    'sale_price' => $price, 'igv_affectation' => $igv, 'min_stock' => $min, 'tracks_expiry' => $tracksExpiry,
                ]);

                foreach ($entries as $i => [$quantity, $cost, $expiresInDays]) {
                    app(InventoryService::class)->registerEntry($product, [
                        'quantity' => $quantity,
                        'received_at' => today()->subDays(10 - $i)->toDateString(),
                        'unit_cost' => (string) $cost,
                        'expires_at' => $expiresInDays !== null ? today()->addDays($expiresInDays)->toDateString() : null,
                        'reference' => 'Carga inicial de demostración',
                    ], $admin);
                }
            }
        });
    }

    /** Ventas demo por el servicio real (numeración, stock y auditoría); una anulada. */
    private function tickets(Company $company, User $admin, User $seller): void
    {
        app(TenantContext::class)->run($company, function () use ($admin, $seller) {
            if (Ticket::exists()) {
                return;
            }

            $id = fn (string $code) => Product::where('code', $code)->value('id');
            $service = app(TicketService::class);

            $sales = [
                [$seller, 'cash', null, [['ARZ-001', '2', null], ['LEC-001', '3', null]]],
                [$seller, 'yape_plin', 'María Quispe', [['YOG-001', '4', '1.00'], ['DEL-001', '1', null]]],
                [$admin, 'card', null, [['AZU-001', '2.500', null]]],
            ];

            foreach ($sales as [$by, $method, $customer, $lines]) {
                $service->issue([
                    'idempotency_key' => (string) Str::uuid(),
                    'payment_method' => $method,
                    'customer_name' => $customer,
                    'lines' => array_map(fn ($l) => ['product_id' => $id($l[0]), 'quantity' => $l[1], 'discount' => $l[2]], $lines),
                ], $by);
            }

            [$voided] = $service->issue([
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'cash',
                'lines' => [['product_id' => $id('ARZ-001'), 'quantity' => '1', 'discount' => null]],
            ], $seller);
            $service->void($voided, 'Venta de demostración anulada', $admin);
        });
    }

    /**
     * Configuración SUNAT demo: credenciales genéricas de beta, un certificado
     * autofirmado de prueba y las series F001 y B001. Intenta validar contra
     * SUNAT beta; si no responde, queda pendiente de validación.
     */
    private function sunat(Company $company, User $admin): void
    {
        app(TenantContext::class)->run($company, function () use ($company, $admin) {
            if (Certificate::exists()) {
                return;
            }

            $config = app(SunatConfigService::class);
            $config->updateCredentials($company, 'MODDATOS', 'moddatos', $admin);
            $config->uploadCertificate($company, $this->selfSignedPfx($company->ruc, 'demo-cert-123'), 'demo-cert-123', $admin);

            $series = app(SeriesService::class);
            $series->create($company, DocumentType::Invoice, 'F001', 0, $admin);
            $series->create($company, DocumentType::Receipt, 'B001', 0, $admin);
            // Series de nota de crédito (spec 007).
            $series->create($company, DocumentType::CreditNote, 'FC01', 0, $admin);
            $series->create($company, DocumentType::CreditNote, 'BC01', 0, $admin);

            try {
                $config->validate($company, $admin);
            } catch (\Throwable) {
                $this->command?->warn('SUNAT beta no respondió: la configuración demo queda pendiente de validación.');
            }
        });
    }

    /**
     * Clientes demo y, si la configuración quedó validada (SUNAT beta
     * respondió), una boleta a «Cliente varios» y una factura emitidas de
     * verdad en beta. Sin red, los comprobantes quedan pendientes y el
     * scheduler los reenvía.
     */
    private function salesDocuments(Company $company, User $seller): void
    {
        app(TenantContext::class)->run($company, function () use ($company, $seller) {
            $rucBase = '2010007097';
            $customers = [
                [CustomerDocumentType::Dni, '46027897', 'MARÍA QUISPE HUAMÁN', null],
                [CustomerDocumentType::Ruc, $rucBase.Ruc::checkDigit($rucBase), 'FERRETERÍA EL SOL S.A.C.', 'Av. Los Pinos 456, Lima'],
            ];
            foreach ($customers as [$type, $number, $name, $address]) {
                Customer::firstOrCreate(
                    ['company_id' => $company->id, 'document_type' => $type, 'document_number' => $number],
                    ['name' => $name, 'address' => $address, 'created_by' => $seller->id],
                );
            }

            if (SalesDocument::exists() || app(SunatConfigService::class)->effectiveStatus($company)[0]->value !== 'validated') {
                return;
            }

            $id = fn (string $code) => Product::where('code', $code)->value('id');
            $service = app(SalesDocumentService::class);
            $service->issue([
                'idempotency_key' => (string) Str::uuid(), 'document_type' => '03', 'payment_method' => 'cash',
                'lines' => [['product_id' => $id('ARZ-001'), 'quantity' => '1'], ['product_id' => $id('LEC-001'), 'quantity' => '2']],
            ], $seller);
            $service->issue([
                'idempotency_key' => (string) Str::uuid(), 'document_type' => '01', 'payment_method' => 'transfer',
                'customer_id' => Customer::where('document_type', CustomerDocumentType::Ruc)->value('id'),
                'lines' => [['product_id' => $id('YOG-001'), 'quantity' => '2', 'discount' => '0.50']],
            ], $seller);
        });
    }

    /** Certificado autofirmado, válido un año, con el RUC en serialNumber. */
    private function selfSignedPfx(string $ruc, string $password): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'PE', 'commonName' => 'EMPRESA DEMO (PRUEBAS)', 'serialNumber' => $ruc], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export($cert, $pfx, $key, $password);

        return $pfx;
    }
}

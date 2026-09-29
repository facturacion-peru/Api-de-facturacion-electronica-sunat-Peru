<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\SalesDocumentStatus;
use App\Enums\SunatEnvironment;
use App\Models\SalesDocument;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Comprobantes sin líneas ni XML real, para pruebas de listado, estados y
 * aislamiento. Los comprobantes completos se emiten con SalesDocumentService.
 *
 * @extends Factory<SalesDocument>
 */
class SalesDocumentFactory extends Factory
{
    private static int $number = 0;

    public function definition(): array
    {
        return [
            'series_id' => Series::factory(),
            'company_id' => fn (array $a) => Series::withoutTenancy()->whereKey($a['series_id'])->value('company_id'),
            'document_type' => fn (array $a) => Series::withoutTenancy()->whereKey($a['series_id'])->value('document_type'),
            'series_code' => fn (array $a) => Series::withoutTenancy()->whereKey($a['series_id'])->value('code'),
            'number' => ++self::$number,
            'environment' => SunatEnvironment::Beta,
            'issued_at' => now(),
            'payment_method' => PaymentMethod::Cash,
            'currency' => 'PEN',
            'issuer_ruc' => '20131312955',
            'issuer_name' => 'EMPRESA DE PRUEBA S.A.C.',
            'issuer_address' => 'AV. DEMO 123',
            'issuer_ubigeo' => '150101',
            'issuer_department' => 'Lima',
            'issuer_province' => 'Lima',
            'issuer_district' => 'Lima',
            'customer_document_type' => '0',
            'customer_document_number' => '-',
            'customer_name' => 'CLIENTES VARIOS',
            'op_gravadas' => '8.47',
            'op_exoneradas' => '0.00',
            'op_inafectas' => '0.00',
            'igv' => '1.53',
            'discount_total' => '0.00',
            'total' => '10.00',
            'status' => SalesDocumentStatus::Accepted,
            'xml' => '<Invoice/>',
            'hash' => 'hash-de-prueba',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function status(SalesDocumentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}

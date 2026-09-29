<?php

namespace Database\Factories;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Certificado con PEM ficticio, para pruebas que no leen el contenido.
 * Las pruebas del certificado real generan uno con openssl.
 *
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'pem' => "-----BEGIN CERTIFICATE-----\nFICTICIO\n-----END CERTIFICATE-----",
            'password' => 'clave-del-certificado',
            'subject' => 'CN=EMPRESA DE PRUEBA',
            'ruc' => fn (array $a) => Company::find($a['company_id'])?->ruc ?? '20131312955',
            'serial_number' => fake()->numerify('##########'),
            'valid_from' => now()->subMonth(),
            'valid_to' => now()->addYear(),
            'status' => CertificateStatus::Current,
        ];
    }

    public function expiringInDays(int $days): static
    {
        return $this->state(fn () => ['valid_to' => now()->addDays($days)]);
    }
}

<?php

namespace App\DocumentLookup;

use App\Enums\CustomerDocumentType;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente de apisperu.com (spec 018). Único punto que conoce la forma de sus
 * respuestas, confirmada en T002: no encontrado es HTTP 200 con
 * `success: false`, y un token inválido, 401. El token va solo en la consulta
 * al servicio: nunca en el log ni en una respuesta (RF-011).
 */
class ApisPeruClient
{
    public function find(CustomerDocumentType $type, string $number): LookupOutcome
    {
        $token = config('services.apisperu.token');
        if (blank($token)) {
            return LookupOutcome::failed(LookupStatus::Disabled);
        }

        $kind = $type === CustomerDocumentType::Ruc ? 'ruc' : 'dni';

        try {
            $response = Http::timeout((int) config('services.apisperu.timeout', 6))
                ->acceptJson()
                ->get(rtrim((string) config('services.apisperu.base_url'), '/')."/{$kind}/{$number}", ['token' => $token]);
        } catch (Throwable $e) {
            $this->warn($kind, 'sin respuesta: '.class_basename($e));

            return LookupOutcome::failed(LookupStatus::Unavailable);
        }

        return $this->translate($type, $kind, $response);
    }

    private function translate(CustomerDocumentType $type, string $kind, Response $response): LookupOutcome
    {
        $json = $response->json();
        $message = is_array($json) ? mb_strtolower((string) ($json['message'] ?? '')) : '';

        if ($response->status() === 429 || str_contains($message, 'límite') || str_contains($message, 'limite')) {
            $this->warn($kind, 'sin cupo');

            return LookupOutcome::failed(LookupStatus::Quota);
        }

        if ($response->status() === 404) {
            return LookupOutcome::failed(LookupStatus::NotFound);
        }

        if (! $response->successful() || ! is_array($json)) {
            $this->warn($kind, 'HTTP '.$response->status());

            return LookupOutcome::failed(LookupStatus::Unavailable);
        }

        if (($json['success'] ?? true) === false) {
            return LookupOutcome::failed(LookupStatus::NotFound);
        }

        $data = $type === CustomerDocumentType::Ruc ? $this->ruc($json) : $this->dni($json);

        return $data === null ? LookupOutcome::failed(LookupStatus::NotFound) : new LookupOutcome(LookupStatus::Found, $data);
    }

    /** @param  array<string, mixed>  $json */
    private function ruc(array $json): ?LookupData
    {
        $name = self::clean($json['razonSocial'] ?? null);
        if ($name === null) {
            return null;
        }

        $address = self::clean($json['direccion'] ?? null);
        $ubigeo = is_string($json['ubigeo'] ?? null) && preg_match('/^\d{6}$/', $json['ubigeo']) ? $json['ubigeo'] : null;

        return new LookupData(
            name: $name,
            address: $address === '-' ? null : $address,
            ubigeo: $ubigeo,
            rucStatus: self::clean($json['estado'] ?? null),
            rucCondition: self::clean($json['condicion'] ?? null),
        );
    }

    /**
     * Persona: «APELLIDO_PATERNO APELLIDO_MATERNO NOMBRES», como SUNAT nombra
     * a las personas con RUC 10 (plan 018).
     *
     * @param  array<string, mixed>  $json
     */
    private function dni(array $json): ?LookupData
    {
        $parts = array_filter([
            self::clean($json['apellidoPaterno'] ?? null),
            self::clean($json['apellidoMaterno'] ?? null),
            self::clean($json['nombres'] ?? null),
        ]);

        return $parts === [] ? null : new LookupData(name: implode(' ', $parts));
    }

    /** Mayúsculas, sin espacios de más; vacío → null. */
    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_strtoupper($value);
    }

    /** Sin el token ni el número consultado. */
    private function warn(string $kind, string $reason): void
    {
        Log::warning("Consulta de {$kind} en apisperu: {$reason}", ['service' => 'apisperu']);
    }
}

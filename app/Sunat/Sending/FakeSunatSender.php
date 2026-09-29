<?php

namespace App\Sunat\Sending;

use App\Enums\SubmissionResult;

/**
 * SUNAT simulada para pruebas: devuelve las respuestas encoladas en orden
 * (la última se repite) y registra cada envío.
 */
final class FakeSunatSender implements SunatSender
{
    /** @var list<SunatResponse> */
    private array $responses;

    /** @var list<array{xml: string, ruc: string}> */
    public array $sent = [];

    public function __construct(SunatResponse ...$responses)
    {
        $this->responses = $responses !== [] ? $responses : [self::accepted()];
    }

    public static function accepted(): SunatResponse
    {
        return new SunatResponse(SubmissionResult::Accepted, '0', 'El comprobante ha sido aceptado', [], 'CDR-ZIP');
    }

    public static function observed(): SunatResponse
    {
        return new SunatResponse(SubmissionResult::Observed, '0', 'Aceptado con observaciones', ['4287 - El precio unitario no coincide'], 'CDR-ZIP');
    }

    public static function rejected(): SunatResponse
    {
        return new SunatResponse(SubmissionResult::Rejected, '2800', 'El dato ingresado en el tipo de documento de identidad del receptor no es válido');
    }

    public static function unreachable(): SunatResponse
    {
        return SunatResponse::unreachable('Could not connect to host', 'HTTP');
    }

    public static function alreadyRegistered(): SunatResponse
    {
        return new SunatResponse(SubmissionResult::Accepted, SunatResponse::ALREADY_REGISTERED, 'El comprobante fue registrado previamente', [SunatResponse::ALREADY_REGISTERED_NOTE]);
    }

    public function send(string $signedXml, string $issuerRuc): SunatResponse
    {
        $this->sent[] = ['xml' => $signedXml, 'ruc' => $issuerRuc];

        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
}

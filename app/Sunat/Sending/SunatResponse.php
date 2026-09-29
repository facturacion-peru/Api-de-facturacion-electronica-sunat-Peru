<?php

namespace App\Sunat\Sending;

use App\Enums\SubmissionResult;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Response\BillResult;

/**
 * Respuesta de SUNAT ya clasificada (plan 005, «Clasificación de la respuesta»):
 * - CDR 0 → aceptado; con observaciones (≥ 4000) → observado.
 * - CDR o error de 1000 a 3999 → rechazado, salvo 1033 (registrado previamente).
 * - Red, tiempo agotado, HTTP (incluido el 401 transitorio de beta) o
 *   excepciones del servicio (0100–0999) → no respondió: se reintenta.
 */
final readonly class SunatResponse
{
    public const ALREADY_REGISTERED = '1033';

    public const ALREADY_REGISTERED_NOTE = 'SUNAT ya lo tenía registrado; CDR no disponible en beta.';

    /** @param  list<string>  $notes */
    public function __construct(
        public SubmissionResult $result,
        public ?string $code = null,
        public ?string $message = null,
        public array $notes = [],
        public ?string $cdrZip = null,
    ) {}

    public static function unreachable(string $message, ?string $code = null): self
    {
        return new self(SubmissionResult::Unreachable, $code, $message);
    }

    public static function fromGreenter(?BaseResult $result): self
    {
        if ($result === null) {
            return self::unreachable('SUNAT no devolvió respuesta.');
        }

        if ($result->isSuccess() && $result instanceof BillResult && $cdr = $result->getCdrResponse()) {
            $code = (string) $cdr->getCode();
            $notes = array_values($cdr->getNotes() ?? []);
            $zip = $result->getCdrZip();

            return match (true) {
                (int) $code >= 2000 && (int) $code < 4000 => new self(SubmissionResult::Rejected, $code, $cdr->getDescription(), $notes, $zip),
                $notes !== [] || (int) $code >= 4000 => new self(SubmissionResult::Observed, $code, $cdr->getDescription(), $notes, $zip),
                default => new self(SubmissionResult::Accepted, $code, $cdr->getDescription(), [], $zip),
            };
        }

        $code = (string) $result->getError()?->getCode();
        $message = (string) $result->getError()?->getMessage();

        if ($code === self::ALREADY_REGISTERED) {
            return new self(SubmissionResult::Accepted, $code, $message, [self::ALREADY_REGISTERED_NOTE]);
        }

        if (ctype_digit($code) && (int) $code >= 1000) {
            return new self(SubmissionResult::Rejected, $code, $message);
        }

        return self::unreachable($message !== '' ? $message : 'SUNAT no respondió.', $code !== '' ? $code : null);
    }
}

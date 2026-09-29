<?php

use App\Enums\SubmissionResult;
use App\Sunat\Sending\FakeSunatSender;
use App\Sunat\Sending\SunatResponse;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\Error;

/*
 * T040 · Clasificación de la respuesta de SUNAT (plan 005) y SUNAT simulada.
 */

function cdr(string $code, array $notes = []): BillResult
{
    return (new BillResult)->setCdrResponse((new CdrResponse)->setCode($code)->setDescription("Respuesta {$code}")->setNotes($notes))
        ->setCdrZip('ZIP')->setSuccess(true);
}

function failure(string $code, string $message): BillResult
{
    return (new BillResult)->setError((new Error)->setCode($code)->setMessage($message))->setSuccess(false);
}

it('clasifica cada respuesta como dice el plan', function (BillResult $result, SubmissionResult $expected) {
    expect(SunatResponse::fromGreenter($result)->result)->toBe($expected);
})->with([
    'CDR 0' => [fn () => cdr('0'), SubmissionResult::Accepted],
    'CDR 0 con notas' => [fn () => cdr('0', ['4287 - precio']), SubmissionResult::Observed],
    'CDR 4000+' => [fn () => cdr('4252'), SubmissionResult::Observed],
    'CDR de rechazo' => [fn () => cdr('2800'), SubmissionResult::Rejected],
    'excepción 1000–1999' => [fn () => failure('1032', 'El comprobante ya esta informado'), SubmissionResult::Rejected],
    'error 2000–3999' => [fn () => failure('3105', 'La serie no corresponde'), SubmissionResult::Rejected],
    '401 transitorio de beta' => [fn () => failure('HTTP', 'Unauthorized'), SubmissionResult::Unreachable],
    'servicio no disponible' => [fn () => failure('0109', 'El sistema no puede responder su solicitud'), SubmissionResult::Unreachable],
    'sin código' => [fn () => failure('', ''), SubmissionResult::Unreachable],
]);

it('guarda el CDR, el código, el mensaje y las notas', function () {
    $response = SunatResponse::fromGreenter(cdr('0', ['4287 - precio']));

    expect([$response->code, $response->message, $response->notes, $response->cdrZip])->toBe(['0', 'Respuesta 0', ['4287 - precio'], 'ZIP']);
});

it('«registrado previamente» (1033) queda aceptado con la nota de R-3', function () {
    $response = SunatResponse::fromGreenter(failure('1033', 'El comprobante fue registrado previamente con otros datos'));

    expect($response->result)->toBe(SubmissionResult::Accepted)
        ->and($response->notes)->toBe([SunatResponse::ALREADY_REGISTERED_NOTE])
        ->and($response->cdrZip)->toBeNull();
});

it('sin respuesta es un «no respondió»', function () {
    expect(SunatResponse::fromGreenter(null)->result)->toBe(SubmissionResult::Unreachable);
});

it('la SUNAT simulada devuelve las respuestas en orden y repite la última', function () {
    $fake = new FakeSunatSender(FakeSunatSender::unreachable(), FakeSunatSender::accepted());

    $results = array_map(fn () => $fake->send('<xml/>', '20131312955')->result, range(1, 3));

    expect($results)->toBe([SubmissionResult::Unreachable, SubmissionResult::Accepted, SubmissionResult::Accepted])
        ->and($fake->sent)->toHaveCount(3);
});

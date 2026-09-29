<?php

use App\Enums\DocumentType;
use App\Enums\IgvAffectation;
use App\Enums\SubmissionResult;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Sunat\DocumentSigner;
use App\Sunat\Sending\GreenterSender;
use App\Sunat\Sending\SunatResponse;
use App\Sunat\TaxCalculator;
use App\Sunat\UblBuilder;
use Tests\Support\TestCertificates;

/*
 * T090 · CE-001 contra SUNAT beta REAL, con el código de producción:
 * TaxCalculator → UblBuilder → DocumentSigner → GreenterSender.
 *
 * No corre en la suite (tests/Beta no está en phpunit.xml): necesita red.
 *   php artisan test tests/Beta/SunatBetaTest.php --group=sunat-beta
 */

uses(Tests\TestCase::class)->group('sunat-beta');

function betaDocument(DocumentType $type, int $number, array $lines, array $customer): SalesDocument
{
    $calc = new TaxCalculator;
    $calculated = [];
    $models = [];
    foreach ($lines as $i => [$qty, $price, $discount, $afe]) {
        $c = $calculated[] = $calc->line($qty, $price, $discount, $afe);
        $models[] = new SalesDocumentLine([
            'position' => $i + 1, 'product_code' => 'P'.($i + 1), 'product_name' => 'PRODUCTO DE PRUEBA '.($i + 1), 'unit' => 'NIU',
            'igv_affectation' => $afe->value, 'quantity' => $qty, 'unit_price' => $price, 'unit_value' => $c->unitValue,
            'gross_amount' => $c->grossAmount, 'discount' => $c->discount, 'base_amount' => $c->baseAmount, 'igv' => $c->igv, 'amount' => $c->amount,
        ]);
    }
    $t = $calc->totals($calculated);
    $document = new SalesDocument([
        'document_type' => $type, 'series_code' => $type === DocumentType::Invoice ? 'F001' : 'B001', 'number' => $number,
        'issued_at' => now(), 'currency' => 'PEN',
        'issuer_ruc' => '20600000013', 'issuer_name' => 'EMPRESA DEMO S.A.C.', 'issuer_trade_name' => 'BODEGA DEMO',
        'issuer_address' => 'AV. DEMO 123', 'issuer_ubigeo' => '150101',
        'issuer_department' => 'Lima', 'issuer_province' => 'Lima', 'issuer_district' => 'Lima',
        'customer_document_type' => $customer[0], 'customer_document_number' => $customer[1], 'customer_name' => $customer[2], 'customer_address' => $customer[3] ?? null,
        'op_gravadas' => $t->opGravadas, 'op_exoneradas' => $t->opExoneradas, 'op_inafectas' => $t->opInafectas,
        'igv' => $t->igv, 'discount_total' => $t->discountTotal, 'total' => $t->total,
    ]);
    $document->setRelation('lines', collect($models));

    return $document;
}

/** Envía y reintenta el 401 transitorio de beta (límite de frecuencia). */
function sendToBeta(SalesDocument $document): SunatResponse
{
    $xml = (new DocumentSigner)->sign((new UblBuilder)->build($document, '0000'), TestCertificates::signingPem())->xml;

    for ($try = 1; $try <= 5; $try++) {
        $response = (new GreenterSender)->send($xml, $document->issuer_ruc);
        if ($response->result !== SubmissionResult::Unreachable) {
            return $response;
        }
        sleep(5 * $try);
    }

    return $response;
}

$anonymous = ['0', '-', 'CLIENTES VARIOS'];

it('SUNAT beta acepta sin observaciones el caso', function (DocumentType $type, array $lines, array $customer) {
    static $number = null;
    $number ??= (int) (time() % 1_000_000);

    $response = sendToBeta(betaDocument($type, $number++, $lines, $customer));

    expect($response->result)->toBe(SubmissionResult::Accepted, "{$response->code} {$response->message} ".implode(' | ', $response->notes))
        ->and($response->notes)->toBe([])
        ->and($response->cdrZip)->not->toBeNull();
})->with([
    'gravado' => [DocumentType::Receipt, [['2', '25.90', '0', IgvAffectation::Gravado]], $anonymous],
    'exonerado' => [DocumentType::Receipt, [['3', '4.50', '0', IgvAffectation::Exonerado]], $anonymous],
    'inafecto' => [DocumentType::Receipt, [['1', '12.00', '0', IgvAffectation::Inafecto]], $anonymous],
    'mixto' => [DocumentType::Receipt, [['2', '25.90', '0', IgvAffectation::Gravado], ['3', '4.50', '0', IgvAffectation::Exonerado], ['1', '12.00', '0', IgvAffectation::Inafecto]], $anonymous],
    'descuento' => [DocumentType::Receipt, [['4', '7.50', '1.00', IgvAffectation::Gravado], ['1', '5.00', '0', IgvAffectation::Gravado]], $anonymous],
    'redondeos límite' => [DocumentType::Receipt, [['3', '0.33', '0', IgvAffectation::Gravado], ['7', '0.10', '0', IgvAffectation::Gravado], ['2.500', '4.20', '0', IgvAffectation::Gravado]], $anonymous],
    'boleta con DNI de más de S/ 700' => [DocumentType::Receipt, [['30', '25.90', '0', IgvAffectation::Gravado]], ['1', '46027897', 'MARIA QUISPE']],
    'factura con RUC' => [DocumentType::Invoice, [['2', '25.90', '0.80', IgvAffectation::Gravado], ['3', '4.50', '0', IgvAffectation::Exonerado]], ['6', '20131312955', 'CLIENTE S.A.C.', 'AV. UNO 123']],
]);

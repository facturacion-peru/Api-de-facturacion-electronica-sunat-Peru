<?php

use App\Enums\DocumentType;
use App\Enums\IgvAffectation;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Sunat\DocumentSigner;
use App\Sunat\TaxCalculator;
use App\Sunat\UblBuilder;
use Tests\Support\TestCertificates;

/*
 * T033 · Del comprobante guardado al XML UBL 2.1 firmado (RF-001, RF-013).
 * Caracteriza la estructura que SUNAT beta aceptó en el spike (T002).
 * Usa la aplicación (casts de fecha de Eloquent) pero no la base de datos.
 */

uses(Tests\TestCase::class);

function documentWith(DocumentType $type, array $lines, array $customer = ['0', '-', 'CLIENTES VARIOS', null]): SalesDocument
{
    $calc = new TaxCalculator;
    $calculated = [];
    $models = [];
    foreach ($lines as $i => [$qty, $price, $discount, $afe]) {
        $c = $calculated[] = $calc->line($qty, $price, $discount, $afe);
        $models[] = new SalesDocumentLine([
            'position' => $i + 1, 'product_code' => 'P'.($i + 1), 'product_name' => 'Producto '.($i + 1), 'unit' => 'NIU',
            'igv_affectation' => $afe->value, 'quantity' => $qty, 'unit_price' => $price, 'unit_value' => $c->unitValue,
            'gross_amount' => $c->grossAmount, 'discount' => $c->discount, 'base_amount' => $c->baseAmount, 'igv' => $c->igv, 'amount' => $c->amount,
        ]);
    }
    $t = $calc->totals($calculated);
    $document = new SalesDocument([
        'document_type' => $type, 'series_code' => $type === DocumentType::Invoice ? 'F001' : 'B001', 'number' => 123,
        'issued_at' => '2026-09-29 10:30:00', 'currency' => 'PEN',
        'issuer_ruc' => '20131312955', 'issuer_name' => 'BODEGA ANA S.A.C.', 'issuer_trade_name' => 'Bodega Ana',
        'issuer_address' => 'AV. UNO 123', 'issuer_ubigeo' => '150101',
        'issuer_department' => 'Lima', 'issuer_province' => 'Lima', 'issuer_district' => 'Miraflores',
        'customer_document_type' => $customer[0], 'customer_document_number' => $customer[1], 'customer_name' => $customer[2], 'customer_address' => $customer[3],
        'op_gravadas' => $t->opGravadas, 'op_exoneradas' => $t->opExoneradas, 'op_inafectas' => $t->opInafectas,
        'igv' => $t->igv, 'discount_total' => $t->discountTotal, 'total' => $t->total,
    ]);
    $document->setRelation('lines', collect($models));

    return $document;
}

function signed(SalesDocument $document): array
{
    $result = (new DocumentSigner)->sign((new UblBuilder)->build($document, '0000'), TestCertificates::make('20131312955')['pem']);
    $xml = new SimpleXMLElement($result->xml);
    foreach (['cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'ds' => 'http://www.w3.org/2000/09/xmldsig#'] as $p => $ns) {
        $xml->registerXPathNamespace($p, $ns);
    }

    return [$result, $xml];
}

$first = fn (SimpleXMLElement $xml, string $path) => (string) ($xml->xpath($path)[0] ?? '');

it('boleta a Cliente varios con gravado, exonerado e inafecto', function () use ($first) {
    [$result, $xml] = signed(documentWith(DocumentType::Receipt, [
        ['2', '25.90', '0', IgvAffectation::Gravado], ['3', '4.50', '0', IgvAffectation::Exonerado], ['1', '12.00', '0', IgvAffectation::Inafecto],
    ]));

    expect($first($xml, '/*/cbc:ID'))->toBe('B001-123')
        ->and($first($xml, '/*/cbc:InvoiceTypeCode'))->toBe('03')
        ->and($first($xml, '/*/cbc:IssueDate'))->toBe('2026-09-29')
        ->and($first($xml, '/*/cbc:DocumentCurrencyCode'))->toBe('PEN')
        ->and($first($xml, '//cac:AccountingSupplierParty//cbc:ID'))->toBe('20131312955')
        ->and($first($xml, '//cac:AccountingSupplierParty//cac:RegistrationAddress/cbc:AddressTypeCode'))->toBe('0000')
        ->and($first($xml, '//cac:RegistrationAddress/cbc:CountrySubentity'))->toBe('LIMA')
        ->and($first($xml, '//cac:RegistrationAddress/cbc:CityName'))->toBe('LIMA')
        ->and($first($xml, '//cac:RegistrationAddress/cbc:District'))->toBe('MIRAFLORES')
        ->and($first($xml, '//cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID/@schemeID'))->toBe('0')
        ->and($first($xml, '//cac:AccountingCustomerParty//cbc:RegistrationName'))->toBe('CLIENTES VARIOS')
        ->and($first($xml, '/*/cac:TaxTotal/cbc:TaxAmount'))->toBe('7.90')
        ->and($first($xml, '/*/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'))->toBe('69.40')
        ->and($first($xml, '/*/cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('77.30')
        ->and($first($xml, "/*/cbc:Note[@languageLocaleID='1000']"))->toBe('SON SETENTA Y SIETE CON 30/100 SOLES')
        ->and(array_map('strval', $xml->xpath('//cac:InvoiceLine//cbc:TaxExemptionReasonCode')))->toBe(['10', '20', '30'])
        ->and(array_map('strval', $xml->xpath('//cac:InvoiceLine//cac:TaxScheme/cbc:ID')))->toBe(['1000', '9997', '9998'])
        ->and($xml->xpath('/*/cac:PaymentTerms'))->toBe([]);
});

it('factura con RUC, forma de pago contado y descuento de línea sin IGV', function () use ($first) {
    [, $xml] = signed(documentWith(DocumentType::Invoice, [['4', '7.50', '1.00', IgvAffectation::Gravado]], ['6', '20100070970', 'CLIENTE S.A.C.', 'AV. DOS 456']));

    expect($first($xml, '/*/cbc:InvoiceTypeCode'))->toBe('01')
        ->and($first($xml, '//cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID'))->toBe('20100070970')
        ->and($first($xml, '//cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID/@schemeID'))->toBe('6')
        ->and($first($xml, '/*/cac:PaymentTerms/cbc:PaymentMeansID'))->toBe('Contado')
        ->and($first($xml, '//cac:InvoiceLine/cac:AllowanceCharge/cbc:AllowanceChargeReasonCode'))->toBe('00')
        ->and($first($xml, '//cac:InvoiceLine/cac:AllowanceCharge/cbc:Amount'))->toBe('0.84')
        ->and($first($xml, '//cac:InvoiceLine/cac:AllowanceCharge/cbc:BaseAmount'))->toBe('25.42')
        ->and($first($xml, '//cac:InvoiceLine/cbc:LineExtensionAmount'))->toBe('24.58')
        ->and($first($xml, "//cac:InvoiceLine//cbc:PriceTypeCode[.='01']/../cbc:PriceAmount"))->toBe('7.5');
});

it('el hash es el DigestValue de la firma', function () use ($first) {
    [$result, $xml] = signed(documentWith(DocumentType::Receipt, [['1', '1.18', '0', IgvAffectation::Gravado]]));

    expect($result->hash)->not->toBeEmpty()
        ->and($result->hash)->toBe($first($xml, '//ds:DigestValue'));
});

it('falla con un certificado ilegible, sin XML a medias', function () {
    expect(fn () => (new DocumentSigner)->sign((new UblBuilder)->build(documentWith(DocumentType::Receipt, [['1', '1.18', '0', IgvAffectation::Gravado]]), '0000'), 'no es un PEM'))
        ->toThrow(App\Sunat\Exceptions\SigningFailed::class);
});

it('007 nota de crédito: documento afectado, motivo, líneas y totales', function () use ($first) {
    $boleta = documentWith(DocumentType::Receipt, [['4', '7.50', '1.00', IgvAffectation::Gravado]]);
    $calc = new App\Sunat\CreditNoteCalculator(new TaxCalculator);
    $c = $calc->line('4', '7.50', '1.00', IgvAffectation::Gravado, '1', App\Sunat\ReturnedSoFar::none());
    $totals = (new TaxCalculator)->totals([$c]);
    $note = new SalesDocument([
        'document_type' => DocumentType::CreditNote, 'series_code' => 'BC01', 'number' => 7, 'issued_at' => '2026-09-30 10:00:00', 'currency' => 'PEN',
        'note_reason_code' => '07', 'note_reason' => 'Devolución de 1 unidad',
        'issuer_ruc' => '20131312955', 'issuer_name' => 'BODEGA ANA S.A.C.', 'issuer_address' => 'AV. UNO 123', 'issuer_ubigeo' => '150101',
        'customer_document_type' => '0', 'customer_document_number' => '-', 'customer_name' => 'CLIENTES VARIOS',
        'op_gravadas' => $totals->opGravadas, 'op_exoneradas' => $totals->opExoneradas, 'op_inafectas' => $totals->opInafectas,
        'igv' => $totals->igv, 'discount_total' => $totals->discountTotal, 'total' => $totals->total,
    ]);
    $note->setRelation('reference', $boleta);
    $note->setRelation('lines', collect([new SalesDocumentLine([
        'position' => 1, 'product_code' => 'P1', 'product_name' => 'Producto 1', 'unit' => 'NIU', 'igv_affectation' => '10',
        'quantity' => '1', 'unit_price' => '7.50', 'unit_value' => $c->unitValue, 'gross_amount' => $c->grossAmount, 'discount' => $c->discount,
        'base_amount' => $c->baseAmount, 'igv' => $c->igv, 'amount' => $c->amount,
    ])]));

    [, $xml] = signed($note);

    expect($xml->getName())->toBe('CreditNote')
        ->and($first($xml, '/*/cbc:ID'))->toBe('BC01-7')
        ->and($first($xml, '//cac:DiscrepancyResponse/cbc:ReferenceID'))->toBe('B001-123')
        ->and($first($xml, '//cac:DiscrepancyResponse/cbc:ResponseCode'))->toBe('07')
        ->and($first($xml, '//cac:DiscrepancyResponse/cbc:Description'))->toBe('DEVOLUCIÓN DE 1 UNIDAD')
        ->and($first($xml, '//cac:BillingReference//cbc:DocumentTypeCode'))->toBe('03')
        ->and($first($xml, '/*/cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('7.25')
        // Sin descuento aparte: la línea va por sus valores netos (la plantilla de nota no los admite).
        ->and($xml->xpath('//cac:CreditNoteLine/cac:AllowanceCharge'))->toBe([])
        ->and($first($xml, '//cac:CreditNoteLine/cbc:LineExtensionAmount'))->toBe('6.14')
        ->and($first($xml, '//cac:CreditNoteLine/cac:Price/cbc:PriceAmount'))->toBe('6.14')
        ->and($first($xml, "//cac:CreditNoteLine//cbc:PriceTypeCode[.='01']/../cbc:PriceAmount"))->toBe('7.25');
});

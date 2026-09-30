<?php

/*
 * Spike de la spec 007 (T002): notas de crédito contra SUNAT beta, sobre una
 * boleta y una factura aceptadas, con series BC01 y FC01 y la regla de
 * prorrateo del plan. Se ejecuta a mano: `php tests/Beta/spike_007.php`.
 */

use App\Enums\IgvAffectation;
use App\Sunat\AmountInWords;
use App\Sunat\CalculatedLine;
use App\Sunat\TaxCalculator;
use App\Support\Decimal;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Charge;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Tests\Support\TestCertificates;

require __DIR__.'/../../vendor/autoload.php';

const RUC = '20600000013';
$calc = new TaxCalculator;

function company(): Company
{
    $address = (new Address)->setUbigueo('150101')->setDepartamento('LIMA')->setProvincia('LIMA')->setDistrito('LIMA')->setDireccion('AV. DEMO 123')->setCodLocal('0000');

    return (new Company)->setRuc(RUC)->setRazonSocial('EMPRESA DEMO S.A.C.')->setNombreComercial('BODEGA DEMO')->setAddress($address);
}

/** @param list<CalculatedLine> $lines @param list<string> $qty @param list<string> $prices */
function fill($doc, array $lines, array $qty, array $prices, TaxCalculator $calc)
{
    $details = [];
    foreach ($lines as $i => $l) {
        $gravado = $l->affectation === IgvAffectation::Gravado;
        $d = (new SaleDetail)->setCodProducto('P'.($i + 1))->setUnidad('NIU')->setCantidad((float) $qty[$i])
            ->setDescripcion('PRODUCTO '.($i + 1))->setMtoValorUnitario((float) $l->unitValue)
            ->setMtoBaseIgv((float) $l->baseAmount)->setPorcentajeIgv($gravado ? 18.0 : 0.0)->setIgv((float) $l->igv)
            ->setTipAfeIgv($l->affectation->value)->setTotalImpuestos((float) $l->igv)->setMtoValorVenta((float) $l->baseAmount)
            ->setMtoPrecioUnitario((float) $prices[$i]);
        if (bccomp($l->discount, '0', 2) > 0) {
            $d->setDescuentos([(new Charge)->setCodTipo('00')->setFactor((float) bcdiv($l->discountBase, $l->grossBase, 5))
                ->setMonto((float) $l->discountBase)->setMontoBase((float) $l->grossBase)]);
        }
        $details[] = $d;
    }
    $t = $calc->totals($lines);

    return $doc->setMtoOperGravadas((float) $t->opGravadas)->setMtoOperExoneradas((float) $t->opExoneradas)->setMtoOperInafectas((float) $t->opInafectas)
        ->setMtoIGV((float) $t->igv)->setTotalImpuestos((float) $t->igv)
        ->setValorVenta((float) bcadd(bcadd($t->opGravadas, $t->opExoneradas, 2), $t->opInafectas, 2))
        ->setSubTotal((float) $t->total)->setMtoImpVenta((float) $t->total)
        ->setDetails($details)->setLegends([(new Legend)->setCode('1000')->setValue(AmountInWords::soles($t->total))]);
}

function send(See $see, $doc): string
{
    for ($try = 1; $try <= 6; $try++) {
        $res = $see->send($doc);
        if ($res->isSuccess()) {
            $cdr = $res->getCdrResponse();

            return "CDR {$cdr->getCode()} {$cdr->getDescription()}".($cdr->getNotes() ? ' · notas: '.implode(' | ', $cdr->getNotes()) : '');
        }
        if ($res->getError()->getCode() !== 'HTTP') {
            return "ERROR {$res->getError()->getCode()} {$res->getError()->getMessage()}";
        }
        sleep(5 * $try);
    }

    return 'ERROR sin respuesta';
}

$see = new See;
$see->setService(SunatEndpoints::FE_BETA);
$see->setCertificate(TestCertificates::make(RUC, 'x')['pem']);
$see->setClaveSOL(RUC, 'MODDATOS', 'moddatos');
$n = (int) (time() % 1000000);

// Boleta: 4 × 7.50 con 1.00 de descuento + 3 × 4.50 exonerado.
$bLines = [$calc->line('4', '7.50', '1.00', IgvAffectation::Gravado), $calc->line('3', '4.50', '0', IgvAffectation::Exonerado)];
$boleta = fill((new Invoice)->setUblVersion('2.1')->setTipoOperacion('0101')->setTipoDoc('03')->setSerie('B001')->setCorrelativo((string) $n)
    ->setFechaEmision(new DateTime)->setTipoMoneda('PEN')->setCompany(company())
    ->setClient((new Client)->setTipoDoc('1')->setNumDoc('46027897')->setRznSocial('MARIA QUISPE')), $bLines, ['4', '3'], ['7.50', '4.50'], $calc);
echo 'Boleta B001-'.$n.' total '.$boleta->getMtoImpVenta().': ', send($see, $boleta), PHP_EOL;

// Factura: 2 × 25.90.
$fLines = [$calc->line('2', '25.90', '0', IgvAffectation::Gravado)];
$factura = fill((new Invoice)->setUblVersion('2.1')->setTipoOperacion('0101')->setTipoDoc('01')->setSerie('F001')->setCorrelativo((string) $n)
    ->setFechaEmision(new DateTime)->setTipoMoneda('PEN')->setCompany(company())->setFormaPago(new FormaPagoContado)
    ->setClient((new Client)->setTipoDoc('6')->setNumDoc('20131312955')->setRznSocial('CLIENTE S.A.C.')), $fLines, ['2'], ['25.90'], $calc);
echo 'Factura F001-'.$n.' total '.$factura->getMtoImpVenta().': ', send($see, $factura), PHP_EOL;

$note = fn (string $series, int $num, string $affectedType, string $affected, string $motive, string $desc, $client) => (new Note)
    ->setUblVersion('2.1')->setTipoDoc('07')->setSerie($series)->setCorrelativo((string) $num)->setFechaEmision(new DateTime)
    ->setTipDocAfectado($affectedType)->setNumDocfectado($affected)->setCodMotivo($motive)->setDesMotivo($desc)
    ->setTipoMoneda('PEN')->setCompany(company())->setClient($client);

// 07 · devolución parcial: 1 de las 4 unidades con descuento prorrateado (1.00 × 1/4 = 0.25).
$disc = Decimal::round(bcdiv(bcmul('1.00', '1', 6), '4', 6));
$partial = [$calc->line('1', '7.50', $disc, IgvAffectation::Gravado)];
$nc1 = fill($note('BC01', $n, '03', "B001-{$n}", '07', 'DEVOLUCION DE 1 UNIDAD', $boleta->getClient()), $partial, ['1'], ['7.50'], $calc);
echo "NC BC01-{$n} (07 parcial, total {$nc1->getMtoImpVenta()}): ", send($see, $nc1), PHP_EOL;

// 01 · anulación del resto de la boleta: 3 unidades con el resto del descuento (0.75) + el exonerado.
$rest = [$calc->line('3', '7.50', bcsub('1.00', $disc, 2), IgvAffectation::Gravado), $calc->line('3', '4.50', '0', IgvAffectation::Exonerado)];
$nc2 = fill($note('BC01', $n + 1, '03', "B001-{$n}", '01', 'ANULACION DE LA OPERACION', $boleta->getClient()), $rest, ['3', '3'], ['7.50', '4.50'], $calc);
echo 'NC BC01-'.($n + 1)." (01 anulación del resto, total {$nc2->getMtoImpVenta()}): ", send($see, $nc2), PHP_EOL;
echo '   Cuadre: boleta ', $boleta->getMtoImpVenta(), ' = notas ', bcadd((string) $nc1->getMtoImpVenta(), (string) $nc2->getMtoImpVenta(), 2), PHP_EOL;

// 06 · devolución total de la factura.
$nc3 = fill($note('FC01', $n, '01', "F001-{$n}", '06', 'DEVOLUCION TOTAL', $factura->getClient()), $fLines, ['2'], ['25.90'], $calc);
echo "NC FC01-{$n} (06 total, total {$nc3->getMtoImpVenta()}): ", send($see, $nc3), PHP_EOL;

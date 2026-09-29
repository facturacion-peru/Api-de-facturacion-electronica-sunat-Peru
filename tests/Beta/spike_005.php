<?php

/*
 * Spike de la spec 005 (T002): envía a SUNAT beta los casos de CE-001 con
 * la regla de cálculo del plan. No es código de producción ni prueba de la
 * suite: se ejecuta a mano con `php tests/Beta/spike_005.php`.
 */

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Charge;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Tests\Support\TestCertificates;

require __DIR__.'/../../vendor/autoload.php';

const RUC = '20600000013';
$precioConDescuento = in_array('--precio-neto', $argv, true);

/** Regla del plan: importe incl. IGV → base e IGV. */
function line(string $qty, string $price, string $disc, string $afe): array
{
    $gross = bcdiv(bcadd(bcmul($qty, $price, 6), '0.005', 6), '1', 2);
    $amount = bcsub($gross, $disc, 2);
    if ($afe === '10') {
        $base = bcdiv(bcadd(bcdiv($amount, '1.18', 6), '0.005', 6), '1', 2);
        $grossBase = bcdiv(bcadd(bcdiv($gross, '1.18', 6), '0.005', 6), '1', 2);
        $unitValue = bcdiv($price, '1.18', 10);
    } else {
        $base = $amount;
        $grossBase = $gross;
        $unitValue = $price;
    }

    return compact('qty', 'price', 'disc', 'afe', 'gross', 'amount', 'base', 'grossBase', 'unitValue') + ['igv' => bcsub($amount, $base, 2)];
}

function build(string $type, string $series, int $number, array $lines, ?array $client, bool $netPrice): Invoice
{
    $address = (new Address)->setUbigueo('150101')->setDepartamento('LIMA')->setProvincia('LIMA')->setDistrito('LIMA')->setDireccion('AV. DEMO 123')->setCodLocal('0000');
    $company = (new Company)->setRuc(RUC)->setRazonSocial('EMPRESA DEMO S.A.C.')->setNombreComercial('BODEGA DEMO')->setAddress($address);
    [$tipo, $num, $name] = $client ?? ['0', '-', 'CLIENTES VARIOS'];
    $cli = (new Client)->setTipoDoc($tipo)->setNumDoc($num)->setRznSocial($name);

    $details = [];
    $tot = ['10' => '0', '20' => '0', '30' => '0', 'igv' => '0', 'total' => '0'];
    foreach ($lines as $i => $l) {
        $d = (new SaleDetail)->setCodProducto('P'.($i + 1))->setUnidad('NIU')->setCantidad((float) $l['qty'])
            ->setDescripcion('PRODUCTO '.($i + 1))->setMtoValorUnitario((float) $l['unitValue'])
            ->setMtoBaseIgv((float) $l['base'])->setPorcentajeIgv($l['afe'] === '10' ? 18.0 : 0.0)->setIgv((float) $l['igv'])
            ->setTipAfeIgv($l['afe'])->setTotalImpuestos((float) $l['igv'])->setMtoValorVenta((float) $l['base'])
            ->setMtoPrecioUnitario((float) ($netPrice ? bcdiv($l['amount'], $l['qty'], 10) : $l['price']));
        if (bccomp($l['disc'], '0', 2) > 0) {
            $discBase = bcsub($l['grossBase'], $l['base'], 2);
            $d->setDescuentos([(new Charge)->setCodTipo('00')->setFactor((float) bcdiv($discBase, $l['grossBase'], 5))
                ->setMonto((float) $discBase)->setMontoBase((float) $l['grossBase'])]);
        }
        $details[] = $d;
        $tot[$l['afe']] = bcadd($tot[$l['afe']], $l['base'], 2);
        $tot['igv'] = bcadd($tot['igv'], $l['igv'], 2);
        $tot['total'] = bcadd($tot['total'], $l['amount'], 2);
    }
    $valorVenta = bcadd(bcadd($tot['10'], $tot['20'], 2), $tot['30'], 2);

    $inv = (new Invoice)->setUblVersion('2.1')->setTipoOperacion('0101')->setTipoDoc($type)->setSerie($series)
        ->setCorrelativo((string) $number)->setFechaEmision(new DateTime)->setTipoMoneda('PEN')
        ->setCompany($company)->setClient($cli)
        ->setMtoOperGravadas((float) $tot['10'])->setMtoOperExoneradas((float) $tot['20'])->setMtoOperInafectas((float) $tot['30'])
        ->setMtoIGV((float) $tot['igv'])->setTotalImpuestos((float) $tot['igv'])->setValorVenta((float) $valorVenta)
        ->setSubTotal((float) $tot['total'])->setMtoImpVenta((float) $tot['total'])
        ->setDetails($details)->setLegends([(new Legend)->setCode('1000')->setValue('SON '.$tot['total'].' SOLES')]);
    if ($type === '01') {
        $inv->setFormaPago(new FormaPagoContado);
    }

    return $inv;
}

$pem = TestCertificates::make(RUC, 'x')['pem'];
$see = new See;
$see->setService(SunatEndpoints::FE_BETA);
$see->setCertificate($pem);
$see->setClaveSOL(RUC, 'MODDATOS', 'moddatos');

$base = (int) (time() % 1000000);
$cases = [
    'gravado' => ['03', 'B001', [line('2', '25.90', '0', '10')], null],
    'exonerado' => ['03', 'B001', [line('3', '4.50', '0', '20')], null],
    'inafecto' => ['03', 'B001', [line('1', '12.00', '0', '30')], null],
    'mixto' => ['03', 'B001', [line('2', '25.90', '0', '10'), line('3', '4.50', '0', '20'), line('1', '12.00', '0', '30')], null],
    'descuento' => ['03', 'B001', [line('4', '7.50', '1.00', '10'), line('1', '5.00', '0', '10')], null],
    'redondeo 3×0.33' => ['03', 'B001', [line('3', '0.33', '0', '10'), line('7', '0.10', '0', '10'), line('2.500', '4.20', '0', '10')], null],
    'redondeo muchas líneas' => ['03', 'B001', array_map(fn ($i) => line('1', '0.0'.(1 + $i % 9), '0', '10'), range(0, 24)), null],
    'boleta con DNI' => ['03', 'B001', [line('30', '25.90', '0', '10')], ['1', '46027897', 'MARIA QUISPE']],
    'factura con RUC' => ['01', 'F001', [line('2', '25.90', '0.80', '10'), line('3', '4.50', '0', '20')], ['6', '20131312955', 'CLIENTE S.A.C.']],
];

$results = [];
foreach ($cases as $name => [$type, $series, $lines, $client]) {
    $doc = build($type, $series, $base++, $lines, $client, $precioConDescuento);
    $t = microtime(true);
    for ($try = 1; $try <= 6; $try++) {
        $res = $see->send($doc);
        if ($res->isSuccess() || $res->getError()->getCode() !== 'HTTP') {
            break;
        }
        sleep(5 * $try); // beta responde 401 transitorio si se envía seguido
    }
    $ms = (int) ((microtime(true) - $t) * 1000);
    $label = "{$name} [{$try}]";
    if ($res->isSuccess()) {
        $cdr = $res->getCdrResponse();
        $line = sprintf('OK    %-28s %s-%d total %s · CDR %s %s%s (%d ms)', $label, $series, $base - 1, $doc->getMtoImpVenta(), $cdr->getCode(), $cdr->getDescription(), $cdr->getNotes() ? ' · notas: '.implode(' | ', $cdr->getNotes()) : '', $ms);
    } else {
        $e = $res->getError();
        $line = sprintf('FALLA %-28s %s · %s (%d ms)', $label, $e->getCode(), $e->getMessage(), $ms);
    }
    echo $line, PHP_EOL;
    $results[$name] = [$doc, $res];
}

// Reenvío del mismo comprobante (HU-4.5, R-3).
[$doc] = $results['gravado'];
for ($try = 1; $try <= 6; $try++) {
    sleep(5 * $try);
    $res = $see->send($doc);
    if ($res->isSuccess() || $res->getError()->getCode() !== 'HTTP') {
        break;
    }
}
echo 'Reenvío: ', $res->isSuccess() ? 'CDR '.$res->getCdrResponse()->getCode().' '.$res->getCdrResponse()->getDescription() : 'error '.$res->getError()->getCode().' '.$res->getError()->getMessage(), PHP_EOL;

// ¿Existe la consulta de CDR en beta? (R-3)
foreach (['beta (misma URL)' => SunatEndpoints::FE_BETA, 'consulta CDR (producción)' => SunatEndpoints::FE_CONSULTA_CDR] as $label => $url) {
    $ch = curl_init($url.'?wsdl');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = (string) curl_exec($ch);
    echo "WSDL {$label}: HTTP ", curl_getinfo($ch, CURLINFO_HTTP_CODE), str_contains($body, 'getStatusCdr') ? ' · ofrece getStatusCdr' : ' · sin getStatusCdr', PHP_EOL;
}

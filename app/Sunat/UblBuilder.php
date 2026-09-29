<?php

namespace App\Sunat;

use App\Enums\DocumentType;
use App\Enums\IgvAffectation;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Charge;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;

/**
 * Del comprobante guardado al Invoice de Greenter (UBL 2.1). Solo copia lo
 * que ya calculó TaxCalculator: aquí no se calcula nada (principio II).
 * Estructura aceptada por SUNAT beta en el spike (T002).
 */
final class UblBuilder
{
    /** Código de tributo y nombre por afectación (catálogo 05). */
    private const TAX_SCHEMES = ['10' => '1000', '20' => '9997', '30' => '9998'];

    public function build(SalesDocument $document, string $establishmentCode): Invoice
    {
        $invoice = (new Invoice)
            ->setUblVersion('2.1')
            ->setTipoOperacion('0101')
            ->setTipoDoc($document->document_type->value)
            ->setSerie($document->series_code)
            ->setCorrelativo((string) $document->number)
            ->setFechaEmision($document->issued_at->toDateTime())
            ->setTipoMoneda($document->currency)
            ->setCompany($this->issuer($document, $establishmentCode))
            ->setClient($this->customer($document))
            ->setMtoOperGravadas((float) $document->op_gravadas)
            ->setMtoOperExoneradas((float) $document->op_exoneradas)
            ->setMtoOperInafectas((float) $document->op_inafectas)
            ->setMtoIGV((float) $document->igv)
            ->setTotalImpuestos((float) $document->igv)
            ->setValorVenta((float) bcadd(bcadd($document->op_gravadas, $document->op_exoneradas, 2), $document->op_inafectas, 2))
            ->setSubTotal((float) $document->total)
            ->setMtoImpVenta((float) $document->total)
            ->setDetails($document->lines->map(fn (SalesDocumentLine $line) => $this->detail($line))->all())
            ->setLegends([(new Legend)->setCode('1000')->setValue(AmountInWords::soles($document->total))]);

        // La factura exige forma de pago; en el MVP siempre al contado (A-22).
        if ($document->document_type === DocumentType::Invoice) {
            $invoice->setFormaPago(new FormaPagoContado);
        }

        return $invoice;
    }

    private function issuer(SalesDocument $document, string $establishmentCode): Company
    {
        $address = (new Address)
            ->setUbigueo($document->issuer_ubigeo)
            ->setDireccion($document->issuer_address)
            ->setCodigoPais('PE')
            ->setCodLocal($establishmentCode);

        return (new Company)
            ->setRuc($document->issuer_ruc)
            ->setRazonSocial($document->issuer_name)
            ->setNombreComercial($document->issuer_trade_name)
            ->setAddress($address);
    }

    private function customer(SalesDocument $document): Client
    {
        $client = (new Client)
            ->setTipoDoc($document->customer_document_type)
            ->setNumDoc($document->customer_document_number)
            ->setRznSocial($document->customer_name);

        if ($document->customer_address) {
            $client->setAddress((new Address)->setDireccion($document->customer_address));
        }

        return $client;
    }

    private function detail(SalesDocumentLine $line): SaleDetail
    {
        $gravado = $line->igv_affectation === IgvAffectation::Gravado->value;

        $detail = (new SaleDetail)
            ->setCodProducto($line->product_code)
            ->setUnidad($line->unit)
            ->setCantidad((float) $line->quantity)
            ->setDescripcion($line->product_name)
            ->setMtoValorUnitario((float) $line->unit_value)
            ->setMtoBaseIgv((float) $line->base_amount)
            ->setPorcentajeIgv($gravado ? (float) TaxCalculator::IGV_RATE : 0.0)
            ->setIgv((float) $line->igv)
            ->setTipAfeIgv($line->igv_affectation)
            ->setTotalImpuestos((float) $line->igv)
            ->setMtoValorVenta((float) $line->base_amount)
            // Precio de referencia: el del catálogo con IGV, sin restar el descuento (spike).
            ->setMtoPrecioUnitario((float) $line->unit_price);

        if (bccomp($line->discount, '0', 2) > 0) {
            $grossBase = bcadd($line->base_amount, $this->discountBase($line), 2);
            $detail->setDescuentos([(new Charge)
                ->setCodTipo('00')
                ->setFactor((float) bcdiv($this->discountBase($line), $grossBase, 5))
                ->setMonto((float) $this->discountBase($line))
                ->setMontoBase((float) $grossBase)]);
        }

        return $detail;
    }

    /** Descuento sin IGV, con la misma regla que TaxCalculator. */
    private function discountBase(SalesDocumentLine $line): string
    {
        return (new TaxCalculator)->line($line->quantity, $line->unit_price, $line->discount, IgvAffectation::from($line->igv_affectation))->discountBase;
    }
}

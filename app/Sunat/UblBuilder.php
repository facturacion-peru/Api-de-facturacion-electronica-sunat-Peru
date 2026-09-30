<?php

namespace App\Sunat;

use App\Enums\DocumentType;
use App\Enums\IgvAffectation;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
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

/**
 * Del comprobante guardado al Invoice de Greenter (UBL 2.1). Solo copia lo
 * que ya calculó TaxCalculator: aquí no se calcula nada (principio II).
 * Estructura aceptada por SUNAT beta en el spike (T002).
 */
final class UblBuilder
{
    /** Código de tributo y nombre por afectación (catálogo 05). */
    private const TAX_SCHEMES = ['10' => '1000', '20' => '9997', '30' => '9998'];

    public function build(SalesDocument $document, string $establishmentCode): Invoice|Note
    {
        if ($document->document_type === DocumentType::CreditNote) {
            return $this->note($document, $establishmentCode);
        }

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

    /**
     * Nota de crédito (spec 007): mismos importes y líneas que un comprobante,
     * más el documento que modifica y el motivo. Sin forma de pago, como el
     * ejemplo de Greenter y el spike (T002).
     */
    private function note(SalesDocument $document, string $establishmentCode): Note
    {
        $reference = $document->reference;

        return (new Note)
            ->setUblVersion('2.1')
            ->setTipoDoc(DocumentType::CreditNote->value)
            ->setSerie($document->series_code)
            ->setCorrelativo((string) $document->number)
            ->setFechaEmision($document->issued_at->toDateTime())
            ->setTipDocAfectado($reference->document_type->value)
            ->setNumDocfectado("{$reference->series_code}-{$reference->number}")
            ->setCodMotivo($document->note_reason_code->value)
            ->setDesMotivo(mb_strtoupper(mb_substr((string) $document->note_reason, 0, 250)))
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
            ->setDetails($document->lines->map(fn (SalesDocumentLine $line) => $this->noteDetail($line))->all())
            ->setLegends([(new Legend)->setCode('1000')->setValue(AmountInWords::soles($document->total))]);
    }

    /**
     * La plantilla de nota de Greenter no admite descuentos de línea: la
     * línea se expresa por sus valores netos (valor unitario = base /
     * cantidad; precio = importe / cantidad), así cuadra sin descuento aparte.
     */
    private function noteDetail(SalesDocumentLine $line): SaleDetail
    {
        $detail = $this->detail($line, withDiscount: false);

        if (bccomp($line->discount, '0', 2) > 0) {
            $detail->setMtoValorUnitario((float) Decimal::round(bcdiv($line->base_amount, $line->quantity, 14), 10))
                ->setMtoPrecioUnitario((float) Decimal::round(bcdiv($line->amount, $line->quantity, 14), 10));
        }

        return $detail;
    }

    private function issuer(SalesDocument $document, string $establishmentCode): Company
    {
        $address = (new Address)
            ->setUbigueo($document->issuer_ubigeo)
            ->setDepartamento(mb_strtoupper((string) $document->issuer_department))
            ->setProvincia(mb_strtoupper((string) $document->issuer_province))
            ->setDistrito(mb_strtoupper((string) $document->issuer_district))
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

    private function detail(SalesDocumentLine $line, bool $withDiscount = true): SaleDetail
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

        if ($withDiscount && bccomp($line->discount, '0', 2) > 0) {
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
        // Desde el bruto guardado: en una nota puede ser un resto (spec 007).
        return (new TaxCalculator)->lineFromGross($line->unit_price, $line->gross_amount, $line->discount, IgvAffectation::from($line->igv_affectation))->discountBase;
    }
}

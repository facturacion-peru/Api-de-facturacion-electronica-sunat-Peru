<?php

namespace App\Printing;

use App\Models\SalesDocument;
use App\Printing\Escpos\Align;
use App\Printing\Escpos\EscposBuilder;
use App\Sales\DocumentPdf;
use App\Sunat\AmountInWords;

/**
 * Boleta, factura o nota de crédito para la impresora térmica (spec 015,
 * A-77): el mismo contenido y orden que la rama de 80 mm de
 * `pdf/sales-document.blade.php`, con el mismo QR, resumen, monto en letras y
 * marca de pruebas (principio II). Sin logo. Espera `lines`, `company` y `reference`.
 */
final class DocumentPrint
{
    private const BETA_MARK = 'PRUEBAS — SIN VALOR LEGAL';

    public function render(SalesDocument $document, PrintOptions $options): string
    {
        $positive = fn (string $amount) => bccomp($amount, '0', 2) > 0;
        $printer = new EscposBuilder($options);

        $printer
            ->text(self::BETA_MARK, Align::Center, bold: true)
            ->feed()
            ->text($document->issuer_trade_name ?: $document->issuer_name, Align::Center, bold: true)
            ->text($document->issuer_name, Align::Center)
            ->text($document->issuer_address, Align::Center)
            ->rule()
            ->text('RUC '.$document->issuer_ruc, Align::Center, bold: true)
            ->text(DocumentPdf::title($document), Align::Center, bold: true)
            ->text($document->display_number, Align::Center, bold: true)
            ->rule()
            ->pair('Fecha de emisión', $document->issued_at->timezone(config('app.timezone'))->format('d/m/Y H:i'))
            ->pair('Cliente', $document->customer_name);

        if ($document->customer_document_number !== '-') {
            $printer->pair(DocumentPdf::CUSTOMER_DOCUMENT_LABELS[$document->customer_document_type] ?? 'Documento', $document->customer_document_number);
        }
        if ($document->customer_address) {
            $printer->pair('Dirección', $document->customer_address);
        }
        $printer->pair('Moneda', 'Soles · al contado ('.$document->payment_method->label().')');
        if ($document->reference) {
            $printer
                ->pair('Documento que modifica', $document->reference->document_type->label().' '.$document->reference->display_number)
                ->pair('Motivo', $document->note_reason_code->value.' · '.$document->note_reason_code->label().': '.$document->note_reason);
        }

        $printer->rule()->text('Cant. Descripción')->row('P. unit.', 'Importe')->rule();
        foreach ($document->lines as $line) {
            $affectation = match ($line->igv_affectation) {
                '10' => '',
                '20' => ' (exonerado)',
                default => ' (inafecto)',
            };
            $printer->text(rtrim(rtrim($line->quantity, '0'), '.').' '.$line->product_name.$affectation);
            if ($positive($line->discount)) {
                $printer->text('Dscto. '.$line->discount);
            }
            $printer->row($line->unit_price, $line->amount);
        }

        $printer->rule();
        foreach ([
            'Op. gravadas' => $document->op_gravadas,
            'Op. exoneradas' => $document->op_exoneradas,
            'Op. inafectas' => $document->op_inafectas,
            'Descuentos (incluidos)' => $document->discount_total,
        ] as $label => $amount) {
            if ($positive($amount)) {
                $printer->row($label, 'S/ '.$amount);
            }
        }

        return $printer
            ->row('IGV (18 %)', 'S/ '.$document->igv)
            ->row('Importe total', 'S/ '.$document->total, bold: true)
            ->feed()
            ->text(AmountInWords::soles($document->total), bold: true)
            ->feed()
            ->qr(DocumentPdf::qrPayload($document))
            ->text('Resumen: '.$document->hash, Align::Center)
            ->rule()
            ->text('Representación impresa de la '.DocumentPdf::REPRESENTATION_NAMES[$document->document_type->value]
                .' electrónica emitida en el ambiente de pruebas de SUNAT.', Align::Center)
            ->text(self::BETA_MARK, Align::Center, bold: true)
            ->feedAndCut()
            ->bytes();
    }
}

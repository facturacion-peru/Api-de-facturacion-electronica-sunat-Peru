<?php

namespace App\Sales;

use App\Enums\DocumentType;
use App\Images\CompanyLogo;
use App\Models\SalesDocument;
use App\Sunat\AmountInWords;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * PDF del comprobante en A4 y 80 mm (spec 005, RF-007). Se genera al
 * descargarlo a partir de los datos guardados, así que siempre coincide con
 * el XML. En beta lleva la marca «PRUEBAS — SIN VALOR LEGAL» (RF-020).
 */
final class DocumentPdf
{
    public const FORMATS = ['a4', '80mm'];

    /** Etiqueta del documento del cliente según su tipo SUNAT (también en la impresión térmica, spec 015). */
    public const CUSTOMER_DOCUMENT_LABELS = ['1' => 'DNI', '4' => 'Carné ext.', '6' => 'RUC'];

    /** Nombre del comprobante en la leyenda de representación impresa. */
    public const REPRESENTATION_NAMES = ['01' => 'factura', '03' => 'boleta de venta', '07' => 'nota de crédito'];

    /** Ancho del papel térmico en puntos (80 mm). */
    private const TICKET_WIDTH_PT = 226.77;

    /** Contenido del QR según SUNAT: RUC|tipo|serie|número|IGV|total|fecha|tipo doc. adquirente|número|hash| */
    public static function qrPayload(SalesDocument $document): string
    {
        return implode('|', [
            $document->issuer_ruc,
            $document->document_type->value,
            $document->series_code,
            (string) $document->number,
            $document->igv,
            $document->total,
            $document->issued_at->format('Y-m-d'),
            $document->customer_document_type,
            $document->customer_document_number,
            $document->hash,
        ]).'|';
    }

    public static function title(SalesDocument $document): string
    {
        return match ($document->document_type) {
            DocumentType::Invoice => 'FACTURA ELECTRÓNICA',
            DocumentType::Receipt => 'BOLETA DE VENTA ELECTRÓNICA',
            DocumentType::CreditNote => 'NOTA DE CRÉDITO ELECTRÓNICA',
        };
    }

    public function html(SalesDocument $document, string $format): string
    {
        $qr = (new PngWriter)->write(new QrCode(data: self::qrPayload($document), size: 240, margin: 4));

        return view('pdf.sales-document', [
            'document' => $document,
            'format' => $format,
            'title' => self::title($document),
            'amountInWords' => AmountInWords::soles($document->total),
            'qr' => $qr->getDataUri(),
            'logo' => app(CompanyLogo::class)->dataUri($document->company?->logo_path),
        ])->render();
    }

    public function render(SalesDocument $document, string $format): string
    {
        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('Helvetica'));
        $dompdf->loadHtml($this->html($document, $format), 'UTF-8');

        if ($format === '80mm') {
            // Alto aproximado según las líneas: el papel térmico es continuo.
            $dompdf->setPaper([0, 0, self::TICKET_WIDTH_PT, 440 + 34 * $document->lines->count()]);
        } else {
            $dompdf->setPaper('A4');
        }

        $dompdf->render();

        return (string) $dompdf->output();
    }
}

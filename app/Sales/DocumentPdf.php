<?php

namespace App\Sales;

use App\Enums\DocumentType;
use App\Models\SalesDocument;
use App\Sunat\AmountInWords;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;

/**
 * PDF del comprobante en A4 y 80 mm (spec 005, RF-007). Se genera al
 * descargarlo a partir de los datos guardados, así que siempre coincide con
 * el XML. En beta lleva la marca «PRUEBAS — SIN VALOR LEGAL» (RF-020).
 */
final class DocumentPdf
{
    public const FORMATS = ['a4', '80mm'];

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

    public function html(SalesDocument $document, string $format): string
    {
        $qr = (new PngWriter)->write(new QrCode(data: self::qrPayload($document), size: 240, margin: 4));
        $logoPath = $document->company?->logo_path;

        return view('pdf.sales-document', [
            'document' => $document,
            'format' => $format,
            'title' => $document->document_type === DocumentType::Invoice ? 'FACTURA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA',
            'amountInWords' => AmountInWords::soles($document->total),
            'qr' => $qr->getDataUri(),
            'logo' => $logoPath && Storage::disk('public')->exists($logoPath)
                ? 'data:'.Storage::disk('public')->mimeType($logoPath).';base64,'.base64_encode(Storage::disk('public')->get($logoPath))
                : null,
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

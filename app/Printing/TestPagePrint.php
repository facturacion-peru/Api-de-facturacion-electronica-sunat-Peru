<?php

namespace App\Printing;

use App\Models\Company;
use App\Printing\Escpos\Align;
use App\Printing\Escpos\EscposBuilder;

/**
 * Hoja de prueba de la impresora (spec 015, HU-1 esc. 3): muestra si el ancho,
 * la tabla de caracteres y el modo de QR elegidos funcionan.
 */
final class TestPagePrint
{
    public const QR_DATA = 'Prueba de impresora';

    public function render(Company $company, PrintOptions $options): string
    {
        return (new EscposBuilder($options))
            ->text($company->nombre_comercial ?: $company->razon_social, Align::Center, bold: true)
            ->text('Hoja de prueba', Align::Center)
            ->text("Papel {$options->width} mm · {$options->columns()} caracteres", Align::Center)
            ->rule()
            ->text(substr(str_repeat('1234567890', 5), 0, $options->columns()))
            ->text('Tildes: áéíóú ÁÉÍÓÚ ñÑ ¿¡ «»')
            ->row('Importe', 'S/ 1,234.50')
            ->rule()
            ->qr(self::QR_DATA)
            ->text($options->qr === 'image' ? 'QR como imagen' : 'QR nativo', Align::Center)
            ->feedAndCut()
            ->bytes();
    }
}

<?php

namespace App\Printing;

/**
 * Ajustes de la impresora térmica que manda la app (spec 015, A-76 y A-77):
 * ancho del papel y si el QR va con el comando nativo o como imagen.
 */
final readonly class PrintOptions
{
    public const WIDTHS = [58, 80];

    public const QR_MODES = ['native', 'image'];

    public function __construct(public int $width = 80, public string $qr = 'native') {}

    /** Caracteres por línea con la fuente A. */
    public function columns(): int
    {
        return $this->width === 58 ? 32 : 48;
    }

    /** Puntos imprimibles por línea. */
    public function dots(): int
    {
        return $this->width === 58 ? 384 : 576;
    }

    /** Tamaño del módulo del QR en puntos. */
    public function qrModule(): int
    {
        return $this->width === 58 ? 5 : 6;
    }
}

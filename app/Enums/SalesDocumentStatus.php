<?php

namespace App\Enums;

/** Estados de un comprobante electrónico (spec 005, RF-010). */
enum SalesDocumentStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Observed = 'observed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de envío',
            self::Sent => 'Enviado',
            self::Accepted => 'Aceptado',
            self::Observed => 'Aceptado con observaciones',
            self::Rejected => 'Rechazado',
        };
    }

    /** Estados definitivos: ya no se envía ni cambia (RF-014). */
    public function isFinal(): bool
    {
        return in_array($this, [self::Accepted, self::Observed, self::Rejected], true);
    }
}

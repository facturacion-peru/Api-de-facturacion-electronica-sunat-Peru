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
    /** Rechazado y dado de baja internamente por el administrador (spec 007, A-42). */
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de envío',
            self::Sent => 'Enviado',
            self::Accepted => 'Aceptado',
            self::Observed => 'Aceptado con observaciones',
            self::Rejected => 'Rechazado',
            self::Discarded => 'Descartado',
        };
    }

    /** Estados definitivos: ya no se envía ni cambia (RF-014). */
    public function isFinal(): bool
    {
        return in_array($this, [self::Accepted, self::Observed, self::Rejected, self::Discarded], true);
    }
}

<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de operación al responsable del SaaS (spec 009, A-48). Lleva solo
 * datos de operación: nunca secretos ni datos de clientes o importes.
 */
class OpsAlert extends Notification
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $detail,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->environment()}] {$this->title}")
            ->greeting($this->title)
            ->line($this->detail)
            ->line('Ambiente: '.$this->environment().' · '.now()->format('d/m/Y H:i'));
    }

    private function environment(): string
    {
        return (string) config('app.env');
    }
}

<?php

namespace App\Notifications;

use App\Enums\CompanyRole;
use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Invitación a una empresa. El token en claro solo viaja en este correo. */
class InvitationNotification extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly Company $company,
        public readonly CompanyRole $role,
        public readonly CarbonInterface $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return config('app.frontend_url').'/invitacion/'.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $empresa = $this->company->nombre_comercial ?? $this->company->razon_social;

        return (new MailMessage)
            ->subject("Invitación a {$empresa}")
            ->greeting('Hola:')
            ->line("Te invitaron a {$empresa} como {$this->role->label()}.")
            ->action('Activar mi cuenta', $this->url())
            ->line("El enlace es de un solo uso y vence el {$this->expiresAt->timezone(config('app.timezone'))->format('d/m/Y H:i')}.")
            ->line('Si no esperabas esta invitación, ignora este correo.');
    }
}

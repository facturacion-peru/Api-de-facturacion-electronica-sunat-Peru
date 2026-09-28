<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Enlace de recuperación hacia la pantalla del frontend (RF-016). */
class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(object $notifiable): string
    {
        return config('app.frontend_url').'/restablecer?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Restablecer tu contraseña')
            ->greeting('Hola:')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $this->url($notifiable))
            ->line("El enlace es de un solo uso y vence en {$minutes} minutos.")
            ->line('Si no lo pediste, ignora este correo: tu contraseña no cambiará.');
    }
}

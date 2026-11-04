<?php

// Notificacion personalizada para restablecimiento de contrasena, con soporte en espanol y URL hacia el frontend.

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordEs extends BaseResetPassword
{
    // Almacena el token y lo pasa al constructor padre.
    /** Pasamos el token al padre */
    public function __construct($token)
    {
        parent::__construct($token);
    }

    // Construye el correo de restablecimiento con la URL generada por AppServiceProvider.
    public function toMail($notifiable)
    {
        // Usa el generador que definiste en AppServiceProvider (FRONTEND_URL/restablecer?token=...&email=...)
        $url = $this->resetUrl($notifiable);

        $minutes = config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
            60
        );

        return (new MailMessage)
            ->subject('Restablecé tu contraseña')
            ->markdown('emails.auth.reset-es', [
                'url'     => $url,
                'user'    => $notifiable,
                'minutes' => $minutes,
            ]);
    }
}

<?php

// Proveedor de servicios principal. Registra observers y personaliza URLs de notificaciones.

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Dominios\Calificaciones\AlumnoCalificacion;
use App\Dominios\Calificaciones\AlumnoCalificacionObserver;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    // Registro de servicios en el contenedor (vacio por defecto).
    public function register(): void
    {
        //
    }

    // Inicializa servicios al arrancar la aplicacion: observers y enlace de restablecimiento de contrasena.
    public function boot(): void
    {
        // Observer existente
        AlumnoCalificacion::observe(AlumnoCalificacionObserver::class);

        /**
         * Link del email de “Olvidé mi contraseña”
         * Lo apuntamos al front en /restablecer con token y email por querystring.
         * La firma con "object $notifiable" es compatible con L10/L11.
         */
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $frontend = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

            // En algunos setups el método existe, sino tomamos ->email
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : $notifiable->email;

            $query = http_build_query([
                'token' => $token,
                'email' => $email,
            ]);

            // 👉 queda así: http://localhost:5173/restablecer?token=...&email=...
            return "{$frontend}/restablecer?{$query}";
        });
    }
}


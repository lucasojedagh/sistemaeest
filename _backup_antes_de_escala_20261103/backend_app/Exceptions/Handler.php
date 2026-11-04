<?php

// Manejador global de excepciones de la aplicacion. Define comportamientos personalizados ante errores.

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    // Registra los callbacks personalizados para reportar excepciones.
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Límite de intentos en criollo (en vez del "Too Many Attempts" en inglés).
        // El tiempo se dice en minutos u horas cuando es largo: "Esperá 2783
        // segundos" no le sirve a nadie.
        $this->renderable(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }
            $segundos = 60;
            try {
                $headers = $e->getHeaders();
                if (isset($headers['Retry-After'])) $segundos = max(1, (int) $headers['Retry-After']);
            } catch (\Throwable $ignored) {}
            if ($segundos >= 7200) {
                $espera = 'unas ' . (int) round($segundos / 3600) . ' horas';
            } elseif ($segundos >= 120) {
                $espera = 'unos ' . (int) round($segundos / 60) . ' minutos';
            } else {
                $espera = $segundos . ' segundos';
            }
            return response()->json([
                'message' => "Demasiados intentos. Esperá {$espera} e intentá de nuevo.",
            ], 429, $e->getHeaders());
        });
    }
}

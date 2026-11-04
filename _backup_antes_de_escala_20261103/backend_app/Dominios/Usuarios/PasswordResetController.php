<?php

namespace App\Dominios\Usuarios;

use App\Http\Controllers\Controller;
use App\Dominios\Usuarios\ApiSesion;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

// Controlador para el restablecimiento de contrasenas via correo electronico.
// Incluye el envio del enlace y la confirmacion del cambio.
class PasswordResetController extends Controller
{
    /** POST /api/password/forgot -> envía email con link (respuesta genérica 200) */
    public function sendLink(Request $request)
    {
        $request->validate(['email' => ['required','email']]);

        try {
            // Dispara el mail (el link se arma en AppServiceProvider::boot)
            $status = Password::sendResetLink($request->only('email'));

            // Laravel frena un segundo pedido dentro del mismo minuto. Antes se
            // respondia igual que cuando si se envio el correo, y la persona
            // se quedaba esperando un correo que nunca llegaba. Ahora se avisa.
            if ($status === Password::RESET_THROTTLED) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Ya te enviamos un enlace hace poco. Esperá un minuto y pedilo de nuevo.',
                ], 429);
            }
            // Siempre 200 con mensaje genérico
            return response()->json([
                'ok'      => true,
                'message' => 'Si el correo existe, recibirás un enlace de restablecimiento.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Password forgot failed', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => app()->isLocal() ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo enviar el correo de restablecimiento.',
            ], 500);
        }
    }

    /** POST /api/password/reset -> cambia la contraseña */
    public function reset(Request $request)
    {
        $request->validate([
            'token'    => ['required','string'],
            'email'    => ['required','email'],
            'password' => ['required','string','min:6','confirmed'], // password + password_confirmation
        ]);

        try {
            $status = Password::reset(
                $request->only('email','password','password_confirmation','token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password'       => Hash::make($password),
                        'remember_token' => Str::random(60),
                        // Invalida el token de API si lo usás para auth
                        'api_token'      => null,
                    ])->save();

                    // Cambiar la clave cierra TODAS las sesiones abiertas:
                    // es la forma de recuperar la cuenta si alguien entró.
                    ApiSesion::cerrarTodas($user->id);
                    \Illuminate\Support\Facades\Cache::forget('auth_token_' . ($user->getOriginal('api_token') ?: ''));

                    event(new PasswordReset($user));
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json(['ok' => true, 'message' => 'Contraseña actualizada correctamente.'], 200);
            }

            // Token inválido/expirado o email no coincide
            return response()->json(['ok' => false, 'message' => __($status)], 422);
        } catch (\Throwable $e) {
            Log::error('Password reset failed', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => app()->isLocal() ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo restablecer la contraseña.',
            ], 500);
        }
    }
}

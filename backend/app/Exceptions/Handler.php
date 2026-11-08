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

        // Un body que supera post_max_size llega descartado: PHP ni lo entrega,
        // Laravel contesta 413 con el mensaje vacío y la pantalla decía "revisá
        // tu conexión", que es mentira. Se dice el límite real del server, que es
        // lo único que el usuario o el administrador pueden revisar.
        $this->renderable(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'El archivo es demasiado grande para este servidor: admite envíos de hasta '
                    . self::limiteLegible(ini_get('post_max_size'))
                    . '. Dividilo en partes más chicas o pedile al administrador que suba ese límite.',
            ], 413);
        });

        // Falla interna: la consulta SQL, los caminos del server y la traza se
        // registran en storage/logs pero no le llegan al usuario. Sirve tanto en
        // desarrollo como en producción porque no depende de APP_DEBUG.
        // Los errores que ya traen un mensaje útil (403, 409, validación, límite
        // de intentos, sesión vencida) siguen su camino normal.
        $this->renderable(function (Throwable $e, $request) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }

            // Un modelo que no existe llega como 404 con texto en inglés y el
            // camino interno de la clase; el usuario solo necesita saber que
            // eso ya no está.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                && $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json([
                    'message' => 'No encontramos eso que buscabas. Puede que se haya borrado.',
                ], 404);
            }

            // Los dos 404/405 que arma Laravel solo (URL mal escrita y método
            // equivocado) salen en inglés y con el nombre interno de la ruta.
            // Son casos raros, pero el usuario los lee en el toast.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->json([
                    'message' => 'Esa dirección no existe en el sistema. Recargá la pantalla y volvé a intentar.',
                ], 404);
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                return response()->json([
                    'message' => 'Esa acción no se puede hacer desde acá. Recargá la pantalla y volvé a intentar.',
                ], 405);
            }

            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            // 1062 = el dato ya estaba guardado (clave única). No es un error del
            // usuario, pero decirle "SQLSTATE[23000]" no le sirve de nada.
            $yaExiste = $e instanceof \Illuminate\Database\QueryException
                && (int) ($e->errorInfo[1] ?? 0) === 1062;

            return response()->json([
                'message' => $yaExiste
                    ? 'Eso ya estaba cargado, así que no se pudo repetir. Revisá los datos antes de guardar de nuevo.'
                    : 'Algo se trabó de nuestro lado y no se pudo completar la operación. Avisá a la dirección para que lo revisemos.',
            ], 500);
        });
    }

    /**
     * "64M", "2G" o los bytes sueltos que devuelve ini_get, escritos como los lee
     * una persona: 64 MB / 2 GB.
     */
    private static function limiteLegible($valor): string
    {
        $valor = trim((string) $valor);
        $unidad = strtoupper(substr($valor, -1));
        $numero = (float) $valor;

        if ($numero <= 0) {
            return 'el máximo configurado';
        }

        return match ($unidad) {
            'K' => round($numero / 1024, 1) . ' MB',
            'M' => $numero . ' MB',
            'G' => $numero . ' GB',
            // Un numero suelto en el ini son bytes, no megas.
            default => $numero < 1048576
                ? round($numero / 1024) . ' KB'
                : round($numero / 1048576, 1) . ' MB',
        };
    }
}

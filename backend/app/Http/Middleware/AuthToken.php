<?php

namespace App\Http\Middleware;

use App\Dominios\Usuarios\ApiSesion;
use App\Dominios\Usuarios\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthToken
{
    // Únicas rutas que pueden abrirse con ?ticket= (iframes y descargas que no
    // pueden mandar header Authorization). El ticket vive 60 segundos y es
    // reutilizable, así que si sirviera para cualquier endpoint bastaría con
    // que se filtrara en un log o un referer para leer toda la API ese minuto.
    private const TICKET_RUTAS = [
        'calificaciones/planilla',
        'calificaciones/planilla/excel',
        'horarios/planilla',
        'print/alumnos',
        'print/asistencia',
    ];

    public function handle(Request $request, Closure $next)
    {
        $sesion = null;
        $auth = $request->header('Authorization');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $token = trim($m[1]);
            $cacheKey = 'auth_token_' . $token;
            $user = Cache::remember($cacheKey, 300, function () use ($token, $request) {
                // 1) Sesion abierta (una por equipo). Es el camino normal.
                $row = ApiSesion::where('token', $token)->first();
                if ($row) {
                    if (!$row->estaViva()) {
                        $row->delete();
                        return null;
                    }
                    $row->marcarUso();
                    return $row->user()->first();
                }
                // 2) Token viejo en users.api_token. En lugar de aceptar que sea
                //    eterno (no tiene fecha de uso y "cerrar todas las sesiones"
                //    no lo alcanzaba), se convierte en una sesion comun con el
                //    mismo token: quien tenia una sesion de antes entra igual y
                //    a partir de ahora vence y se cierra como todas.
                $legado = User::where('api_token', $token)->first();
                if (!$legado) return null;
                self::convertirLegadoEnSesion($legado, $token, $request);
                return $legado;
            });

            if (!$user || $user->status !== 'active') {
                // La app redirige a /login con el 401, pero el texto igual puede
                // verse (un fetch que muestra el error, un iframe abierto a
                // media sesión): que sea legible y en español.
                return response()->json(['message' => 'La sesión venció. Volvé a ingresar.'], 401);
            }
        } else {
            // Ticket de impresión (ver AuthApiController@printTicket): vale 60s
            // y es reutilizable dentro de ese plazo. Se eligió reutilizable (en
            // vez de un solo uso) porque los navegadores pueden pedir la misma
            // URL más de una vez (doble montaje en dev, prefetch de extensiones,
            // reintentos): con un solo uso el segundo pedido caía en 401.
            // El api_token real solo viaja en header Authorization.
            $ticket = trim((string) $request->query('ticket', ''));
            if ($ticket === '' || !$this->esRutaImprimible($request)) {
                // Este 401 se ve en la pantalla: es el iframe de la planilla
                // cuando el enlace se abrió sin ticket o venció.
                return response()->json(['message' => 'El enlace de impresión venció. Volvé a abrirlo desde la pantalla.'], 401);
            }
            $userId = Cache::get('print_ticket_' . $ticket);
            $user = $userId
                ? User::where('id', $userId)->where('status', 'active')->first()
                : null;
            if (!$user) {
                return response()->json(['message' => 'El enlace de impresión venció. Volvé a abrirlo desde la pantalla.'], 401);
            }
        }

        $request->setUserResolver(fn() => $user);

        return $next($request);
    }

    private function esRutaImprimible(Request $request): bool
    {
        if (!$request->isMethod('GET')) {
            return false;
        }
        $uri = $request->route()?->uri() ?: trim($request->path(), '/');

        return in_array(trim($uri, '/'), self::TICKET_RUTAS, true);
    }

    // Pasa un users.api_token a la tabla de sesiones y vacia la columna. Se
    // borra solo si todavia tiene ese token: si otro pedido lo cambio entre
    // medio, no pisa el nuevo.
    private static function convertirLegadoEnSesion(User $user, string $token, Request $request): void
    {
        try {
            ApiSesion::abrir((int) $user->id, $request, $token);
        } catch (\Illuminate\Database\QueryException) {
            // Concurrencia: otra request ya creó la fila con ese token unico.
        }
        User::where('id', $user->id)->where('api_token', $token)->update(['api_token' => null]);
    }
}

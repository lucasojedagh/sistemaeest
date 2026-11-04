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
            $user = Cache::remember($cacheKey, 300, function () use ($token) {
                // 1) Sesion abierta (una por equipo). Es el camino normal.
                $row = ApiSesion::where('token', $token)->first();
                if ($row) {
                    $row->marcarUso();
                    return $row->user()->first();
                }
                // 2) Respaldo: token viejo en users.api_token, para que las
                //    sesiones abiertas antes de esta migracion sigan valiendo.
                return User::where('api_token', $token)->first();
            });

            if (!$user || $user->status !== 'active') {
                return response()->json(['message' => 'Unauthorized'], 401);
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
                return response()->json(['message' => 'Unauthorized'], 401);
            }
            $userId = Cache::get('print_ticket_' . $ticket);
            $user = $userId
                ? User::where('id', $userId)->where('status', 'active')->first()
                : null;
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
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
}

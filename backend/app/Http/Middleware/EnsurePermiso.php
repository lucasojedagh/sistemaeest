<?php

// Middleware de permisos por catálogo. Se aplica al grupo autenticado completo y
// solo actúa cuando el rol del usuario tiene una fila en roles_config (un rol
// personalizado, o un rol de sistema al que se le quitaron permisos).
// Las cuentas actuales, sin fila, pasan directo: no cambia nada su comportamiento.

namespace App\Http\Middleware;

use App\Support\Permisos;
use Closure;
use Illuminate\Http\Request;

class EnsurePermiso
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $rol = $user->role;
        if (Permisos::sinFiltro($rol)) {
            return $next($request);
        }

        $clave  = Permisos::claveDel($request);

        if ($clave === null) {
            // Ruta no catalogada: los roles de sistema con quitas siguen la regla de
            // siempre; un rol personalizado no puede usarla (lista blanca cerrada).
            if (Permisos::esPersonalizado($rol) && !Permisos::rutaConocidaRequest($request)) {
                return response()->json(['message' => 'Tu rol no tiene permiso para esta acción.'], 403);
            }
            return $next($request);
        }

        if (!Permisos::puede($rol, $clave)) {
            return response()->json(['message' => 'Tu rol no tiene permiso para esta acción.'], 403);
        }

        return $next($request);
    }
}

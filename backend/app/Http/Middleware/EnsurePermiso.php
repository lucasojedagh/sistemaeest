<?php

// Middleware de permisos por catálogo. Se aplica al grupo autenticado completo.
// La clave de la ruta se exige siempre: para un rol sin lista guardada,
// Permisos::puede() contesta con la base de su rango, así que el catálogo manda
// también en las rutas de lectura que no llevan middleware 'role:'. Sin esto,
// una cuenta sin rol (nivel 0, base vacía) entraba a profesores, documentos,
// dashboard y asistencia porque no tenía fila en roles_config.

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

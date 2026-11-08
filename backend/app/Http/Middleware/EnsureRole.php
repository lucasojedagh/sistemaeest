<?php

// Middleware de autorizacion por rol. Verifica que el usuario autenticado
// tenga al menos el nivel minimo de los roles indicados (jerarquia de
// RoleHierarchy: admin 5 > directivo 4 > jefe 3 > preceptor 2 > profesor 1).
// Ej: role:admin,directivo,jefe_preceptores exige nivel >= 3.
//
// Excepción: un rol con configuración guardada en "Roles y permisos" se rige por
// el catálogo de acciones. Si la ruta tiene permiso y el rol lo tiene, entra
// aunque su rango no alcance; si no lo tiene, afuera. Así la casilla marcada en
// la pantalla decide de verdad y no queda tapada por la jerarquía histórica.

namespace App\Http\Middleware;

use App\Support\Permisos;
use App\Support\RoleHierarchy;
use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    // role:X admite a X y a todo rol superior. Con varios roles vale el menor
    // nivel listado (ej: role:admin,jefe_preceptores admite tambien a directivo).
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!Permisos::sinFiltro($user->role)) {
            $clave = Permisos::claveDel($request);
            if ($clave !== null) {
                return Permisos::puede($user->role, $clave)
                    ? $next($request)
                    : response()->json(['message' => 'Tu rol no tiene permiso para esta acción.'], 403);
            }
        }

        $min = null;
        foreach ($roles as $r) {
            $lvl = RoleHierarchy::level($r);
            if ($lvl > 0 && ($min === null || $lvl < $min)) {
                $min = $lvl;
            }
        }
        if ($min === null || RoleHierarchy::level($user->role) < $min) {
            // La pantalla muestra este texto en el toast: "Forbidden" en inglés
            // no le dice nada a un preceptor, y sí le dice que no puede.
            return response()->json(['message' => 'Tu rol no llega al rango que pide esta acción.'], 403);
        }

        return $next($request);
    }
}

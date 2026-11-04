<?php

// Middleware de autorizacion por rol. Verifica que el usuario autenticado
// tenga al menos el nivel minimo de los roles indicados (jerarquia de
// RoleHierarchy: admin 5 > directivo 4 > jefe 3 > preceptor 2 > profesor 1).
// Ej: role:admin,directivo,jefe_preceptores exige nivel >= 3.

namespace App\Http\Middleware;

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

        $min = null;
        foreach ($roles as $r) {
            $lvl = RoleHierarchy::level($r);
            if ($lvl > 0 && ($min === null || $lvl < $min)) {
                $min = $lvl;
            }
        }
        if ($min === null || RoleHierarchy::level($user->role) < $min) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}

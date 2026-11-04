<?php

// Middleware base de autenticacion. Redirige a login cuando el usuario no esta autenticado.

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    // Determina la ruta de redireccion segun el tipo de request (JSON vs. navegador).
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}

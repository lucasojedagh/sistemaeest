<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toda respuesta de /api es JSON, incluso cuando el cliente no manda
 * "Accept: application/json" (un fetch de blob, un iframe, curl o un
 * window.open). Sin esto, un error de validación o de auth en esas rutas
 * contesta un 302 con HTML hacia FRONTEND_URL: la pantalla no muestra el
 * motivo y, si era una descarga, baja un archivo podrido.
 */
class FuerzaJsonApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}

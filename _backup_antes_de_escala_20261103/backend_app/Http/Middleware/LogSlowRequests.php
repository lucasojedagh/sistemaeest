<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// Registra en el log los pedidos que tardan más del umbral, para detectar
// lentitud en producción antes de que los usuarios se quejen.
// Umbral configurable con SLOW_REQUEST_MS (default 2000).
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $inicio = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $ms = (int) round((microtime(true) - $inicio) * 1000);
        $umbral = (int) (env('SLOW_REQUEST_MS', 2000) ?: 2000);

        if ($ms >= $umbral) {
            Log::warning('Pedido lento', [
                'metodo' => $request->getMethod(),
                'ruta'   => '/' . ltrim($request->path(), '/'),
                'ms'     => $ms,
                'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
                'user'   => optional($request->user())->email,
            ]);
        }

        return $response;
    }
}

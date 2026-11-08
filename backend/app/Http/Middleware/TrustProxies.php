<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * En el server el único que llega a PHP-FPM es el nginx del compose
     * (backend:9000 no se publica al host), y ese nginx pisa X-Forwarded-For
     * con la IP real del navegador: ver docker/nginx/default.conf. Por eso se
     * confía en el proxy que sea. Sin esto, Laravel ve la IP del contenedor y
     * el historial, las sesiones y el cupo de pedidos por IP quedan todos
     * agrupados en una sola dirección para toda la escuela.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * Sólo la IP y el esquema: X-Forwarded-Host y -Port los manda el navegador
     * y no se pisan en nginx, así que no se confían (el Host real ya llega en
     * la cabecera Host).
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_PROTO;
}

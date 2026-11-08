<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Un filtro de la barra de direcciones es un valor suelto, pero puede llegar
 * como lista: "?division[]=1" lo arma cualquier serializador de parámetros, un
 * enlace guardado o una URL copiada de otro sistema. Los controladores lo pasan
 * derecho a trim() o strtoupper() y PHP corta con "Array to string conversion";
 * la pantalla entera terminaba en "Algo se trabó de nuestro lado" sin que
 * nadie haya tocado nada raro.
 *
 * Se aplanan acá y no en cada controlador porque son más de 60 lecturas
 * repartidas en todos los módulos, y la que se agregue mañana tiene que quedar
 * cubierta sin acordarse de repetir el cuidado.
 *
 * Sólo se toca el query de la URL: los cuerpos siguen aceptando listas (las
 * filas de la planilla, los alumnos de un lote).
 */
class AplanaFiltrosQuery
{
    /**
     * Se toma el primer valor aunque la lista tenga varios. Es la misma
     * respuesta que da un filtro escrito mal a mano ("?division=Z" no encuentra
     * a nadie), así que la pantalla queda vacía en lugar de mostrar el colegio
     * entero creyendo que filtró.
     */
    public function handle(Request $request, Closure $next)
    {
        foreach ($request->query->all() as $clave => $valor) {
            if (!is_array($valor)) {
                continue;
            }

            $primero = reset($valor);
            $request->query->set($clave, is_scalar($primero) ? (string) $primero : '');
        }

        return $next($request);
    }
}

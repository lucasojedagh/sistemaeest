<?php

namespace App\Support;

use Illuminate\Http\Request;

// Paginado seguro para los listados.
// Convierte ?per_page= en un entero entre 1 y el maximo: si viene negativo,
// cero o texto, usa el valor por defecto en vez de romper el SQL con un 500.
class Paginacion
{
    public static function porPagina(Request $r, int $defecto = 50, int $max = 200): int
    {
        $crudo = $r->query('per_page', $defecto);

        if (is_array($crudo) || (is_string($crudo) && !preg_match('/^-?\d+$/', trim($crudo)))) {
            return $defecto;
        }

        $n = (int) $crudo;
        if ($n < 1) {
            return $defecto;
        }

        return min($n, $max);
    }
}

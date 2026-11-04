<?php

namespace App\Support;

// Utilidades para construir patrones LIKE seguros, escapando los comodines
// SQL (% y _) que el usuario podria ingresar para evitar filtrado de datos.
class LikeSearch
{
    /**
     * Escapa los comodines % y _ y encierra el termino entre % % para busqueda parcial.
     * Usar SIEMPRE con where(... 'like', LikeSearch::contains($q)) para evitar
     * que el usuario inyecte comodines y extraiga registros arbitrarios.
     */
    public static function contains(string $term): string
    {
        return '%' . self::escape($term) . '%';
    }

    /**
     * Escapa los comodines SQL (% y _) y el caracter de escape usado.
     */
    public static function escape(string $term): string
    {
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        return $term;
    }
}

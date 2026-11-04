<?php

namespace App\Support;

use App\Dominios\Cursos\ParametroDivision;

/**
 * Fuente única de verdad para rangos de años escolares.
 * Evita topes hardcoded inconsistentes entre controladores.
 */
class SchoolYear
{
    /** Año máximo configurado (ParametroDivision activos); fallback 7: siempre hay 7 años. */
    public static function maxYear(): int
    {
        $max = (int) (ParametroDivision::where('activo', true)->max('anio') ?? 0);
        return $max > 0 ? $max : 7;
    }

    /** Año mínimo (siempre 1). */
    public static function minYear(): int
    {
        return 1;
    }
}

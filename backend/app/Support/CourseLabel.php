<?php

namespace App\Support;

/**
 * Genera las etiquetas visibles de cursos (año + división) en formato ordinal.
 *
 * Formato unificado en todo el sistema:
 *   año 1 -> "1ro", 2 -> "2do", 3 -> "3ro", 4 -> "4to", 5 -> "5to",
 *   6 -> "6to", 7 -> "7mo"
 *   división 1 -> "1ra", 2 -> "2da", 3 -> "3ra", 4 -> "4ta", 5 -> "5ta",
 *   6 -> "6ta", 7 -> "7ma", 8 -> "8va", 9 -> "9na"
 *
 * Es la única fuente de verdad en el backend para convertir los datos
 * (anio: int, division: string) a la etiqueta visible. El frontend usa un
 * módulo equivalente (src/lib/cursos.js) para mantener consistencia.
 */
class CourseLabel
{
    /** Año máximo soportado. */
    public const MAX_ANIO = 7;

    /** Ordinales de año (índice = año - 1). */
    private const ANIO_ORDINAL = ['1.º', '2.º', '3.º', '4.º', '5.º', '6.º', '7.º'];

    /** Ordinales de división (índice = división - 1). */
    private const DIVISION_ORDINAL = ['1.ª', '2.ª', '3.ª', '4.ª', '5.ª', '6.ª', '7.ª', '8.ª', '9.ª'];

    /**
     * Año en formato corto, como en la planilla oficial: "1ro", "2do"...
     * (el rótulo CURSO de la planilla en papel).
     */
    public static function anioCorto($anio): string
    {
        static $cortos = ['1ro', '2do', '3ro', '4to', '5to', '6to', '7mo'];
        $n = (int) $anio;
        if ($n < 1 || $n > self::MAX_ANIO) return '';
        return $cortos[$n - 1];
    }

    /**
     * División en formato corto, como en la planilla oficial: "1ra", "2da"...
     * (el rótulo DIVISIÓN de la planilla en papel).
     */
    public static function divisionCorta($division): string
    {
        static $cortas = ['1ra', '2da', '3ra', '4ta', '5ta', '6ta', '7ma', '8va', '9na'];
        if ($division === null || $division === '' || $division === false) return '';
        if (!is_numeric($division)) return '';
        $n = (int) $division;
        if ($n < 1 || $n > count($cortas)) return '';
        return $cortas[$n - 1];
    }

    /**
     * Convierte un año numérico (1-7) a su ordinal visible.
     * Devuelve '' si no es válido.
     */
    public static function anio($anio): string
    {
        $n = (int) $anio;
        if ($n < 1 || $n > self::MAX_ANIO) return '';
        return self::ANIO_ORDINAL[$n - 1];
    }

    /**
     * Convierte una división ("1"-"9", null) a su ordinal visible.
     * Devuelve '' si no hay división o es inválida.
     */
    public static function division($division): string
    {
        if ($division === null || $division === '' || $division === false) return '';
        $n = (int) $division;
        if ($n < 1 || $n > count(self::DIVISION_ORDINAL)) return '';
        return self::DIVISION_ORDINAL[$n - 1];
    }

    /**
     * Construye la etiqueta visible de un curso.
     *   label(3, '2') -> "3ro 2da"
     *   label(3, null) -> "3ro"
     */
    public static function label($anio, $division = null): string
    {
        $a = self::anio($anio);
        if ($a === '') return '';
        $d = self::division($division);
        return $d !== '' ? "{$a} {$d}" : $a;
    }
}

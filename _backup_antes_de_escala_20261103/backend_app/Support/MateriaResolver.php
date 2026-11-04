<?php

namespace App\Support;

use App\Dominios\Cursos\Materia;
use Illuminate\Support\Facades\DB;

// Hay materias homónimas en un mismo año que cambian solo de especialidad
// (ej. "DIBUJO TECNOLÓGICO" existe en electromecánica y en MMO). Buscarla
// únicamente por (año, nombre) devuelve la primera de la tabla y termina
// asignando la especialidad equivocada. Este helper desempata con la
// especialidad del curso (anio, division).
class MateriaResolver
{
    /** @var array<string, string|null> anio|division => especialidad */
    private static array $espPorCurso = [];

    public static function porNombre(?int $anio, ?string $division, string $nombre): ?Materia
    {
        $limpio = trim($nombre);
        if ($limpio === '') {
            return null;
        }

        $candidatas = Materia::query()
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($limpio, 'UTF-8')])
            ->when($anio, fn ($q) => $q->where('anio', $anio))
            ->orderBy('id')
            ->get();

        if ($candidatas->isEmpty()) {
            return null;
        }
        if ($candidatas->count() === 1) {
            return $candidatas->first();
        }

        // Primero las activas; si todas están inactivas se conserva la elección
        // vieja en lugar de devolver null (evita crear una materia duplicada).
        $activas = $candidatas->where('activo', true)->values();
        $posibles = $activas->isNotEmpty() ? $activas : $candidatas;

        $esp = self::especialidadCurso($anio, $division);
        if ($esp !== null) {
            $filtradas = $posibles->where('especialidad', $esp)->values();
            if ($filtradas->count() === 1) {
                return $filtradas->first();
            }
        }

        return $posibles->first();
    }

    private static function especialidadCurso(?int $anio, ?string $division): ?string
    {
        $div = self::divisionChar1($division);
        if (!$anio || $div === null) {
            return null;
        }
        $key = $anio . '|' . $div;
        if (!array_key_exists($key, self::$espPorCurso)) {
            self::$espPorCurso[$key] = DB::table('cursos')
                ->where('anio', $anio)
                ->where('division', $div)
                ->value('especialidad');
        }
        $esp = self::$espPorCurso[$key];

        return is_string($esp) && $esp !== '' ? $esp : null;
    }

    private static function divisionChar1(?string $d): ?string
    {
        $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $d) ?? '');
        return $t === '' ? null : substr($t, 0, 1);
    }
}

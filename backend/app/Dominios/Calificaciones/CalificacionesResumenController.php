<?php

namespace App\Dominios\Calificaciones;

use App\Http\Controllers\Controller;

use App\Support\Alcances;
use App\Support\AuthorizesScope;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Controlador para obtener un resumen agregado de calificaciones.
// Calcula los promedios de los periodos 1 y 2 por alumno utilizando la tabla
// de calificaciones disponible, con deteccion automatica de esquema.
class CalificacionesResumenController extends Controller
{
    use AuthorizesScope;
    private function cachedSchemaTable(): ?string
    {
        $key = 'calif_resumen_table';
        $val = Cache::remember($key, 86400, function () {
            $candidates = ['alumno_calificaciones','alumno_calificacions','calificaciones','notas'];
            foreach ($candidates as $t) {
                if (Schema::hasTable($t)) return $t;
            }
            return null;
        });
        return $val;
    }

    private function cachedHasColumn(string $table, array $candidates): ?string
    {
        $key = 'calif_cols_' . $table . '_' . md5(implode(',', $candidates));
        return Cache::remember($key, 86400, function () use ($table, $candidates) {
            foreach ($candidates as $c) {
                if (Schema::hasColumn($table, $c)) return $c;
            }
            return null;
        });
    }

    // Obtiene un resumen de calificaciones con promedios de P1 y P2 por alumno.
    // Detecta automaticamente la tabla y columnas disponibles en el esquema actual.
    public function index(Request $request)
    {
        // Endpoint agregado con promedios de todos los alumnos: el alcance de
        // lectura de calificaciones del rol decide.
        if (!$this->puedeVer($request, 'calificaciones')) {
            abort(403, 'No tenés permiso para ver resumenes de calificaciones.');
        }

        $ciclo = $request->integer('ciclo');

        $table = $this->cachedSchemaTable();
        if (!$table) return response()->json([]);

        $colAlumno  = $this->cachedHasColumn($table, ['alumno_id','alumnoId','id_alumno']);
        $colPeriodo = $this->cachedHasColumn($table, ['periodo','cuatrimestre']);
        $colCiclo   = $this->cachedHasColumn($table, ['ciclo_lectivo','ciclo']);
        $colValor   = $this->cachedHasColumn($table, ['valor','nota','calificacion','value','puntaje']);
        $colAnio    = $this->cachedHasColumn($table, ['anio','year']);
        $colDivision= $this->cachedHasColumn($table, ['division']);

        if (!$colAlumno || !$colPeriodo || !$colValor) {
            return response()->json([]); // faltan columnas clave
        }

        // Consulta agregada (promedios P1/P2 por alumno) directamente en SQL.
        // Solo notas regulares: intensificaciones, preliminares y finales
        // no promedian con los parciales (misma regla que el resto).
        $q = DB::table($table)
            ->selectRaw("
                {$colAlumno}   as alumno_id,
                SUM(CASE WHEN {$colPeriodo} = 1 THEN {$colValor} ELSE 0 END)       as s1,
                SUM(CASE WHEN {$colPeriodo} = 1 THEN 1           ELSE 0 END)       as n1,
                SUM(CASE WHEN {$colPeriodo} = 2 THEN {$colValor} ELSE 0 END)       as s2,
                SUM(CASE WHEN {$colPeriodo} = 2 THEN 1           ELSE 0 END)       as n2
            ")
            ->whereIn($colPeriodo, [1,2]);
        if ($colTipo = $this->cachedHasColumn($table, ['tipo_calificacion', 'tipo'])) {
            $q->where(function ($w) use ($colTipo) {
                $w->whereNull($colTipo)->orWhere($colTipo, 'regular');
            });
        }
        $q->groupBy($colAlumno);

        if ($ciclo && $colCiclo) {
            $q->where($colCiclo, $ciclo);
        }

        // Restringe el alcance según el alcance de lectura de calificaciones del
        // rol: usa assignedPairs (une asignaciones de usuario y ficha profesional por email).
        if ($this->alcanceVer($request, 'calificaciones') !== Alcances::TODO) {
            $assigns = collect($this->assignedPairs($request))
                ->filter(fn ($p) => !empty($p['anio']))
                ->map(fn ($p) => [(int) $p['anio'], $p['division'] ?? null])
                ->values();

            if ($assigns->isEmpty() || !$colAnio || !$colDivision) {
                return response()->json([]);
            }

            $q->where(function ($sub) use ($assigns, $colAnio, $colDivision) {
                foreach ($assigns as [$anio, $division]) {
                    $sub->orWhere(function ($w) use ($anio, $division, $colAnio, $colDivision) {
                        $w->where($colAnio, $anio);
                        if ($division === null) {
                            $w->whereNull($colDivision);
                        } else {
                            $w->where($colDivision, $division);
                        }
                    });
                }
            });
        }

        $rows = $q->get();

        $out = [];
        foreach ($rows as $r) {
            $p1  = ($r->n1 ?? 0) ? round($r->s1 / $r->n1, 2) : null;
            $p2  = ($r->n2 ?? 0) ? round($r->s2 / $r->n2, 2) : null;
            $totS = (float) ($r->s1 + $r->s2);
            $totN = (int)   (($r->n1 ?? 0) + ($r->n2 ?? 0));
            $gen  = $totN ? round($totS / $totN, 2) : null;

            $out[] = [
                'alumno_id' => (int) $r->alumno_id,
                'p1'        => $p1,
                'p2'        => $p2,
                'general'   => $gen,
            ];
        }

        // Ordenado por alumno para estabilidad
        usort($out, fn($a,$b) => $a['alumno_id'] <=> $b['alumno_id']);

        return response()->json($out);
    }

}


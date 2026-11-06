<?php

namespace App\Dominios\Calificaciones;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Calificaciones\AlumnoPromedio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

// Controlador para consultar los promedios calculados de un alumno.
// Agrupa los promedios por materia y periodo a partir de la tabla alumno_promedios.
class AlumnoPromedioController extends Controller
{
    use \App\Support\AuthorizesScope;

    // Obtiene los promedios del alumno agrupados por materia, con filtros opcionales por anio, division y ciclo lectivo.
    public function index(Request $req, $alumnoId)
    {
        // Si por alguna razón no existe la tabla, devolvemos vacío sin 500.
        if (!Schema::hasTable('alumno_promedios')) {
            return response()->json(['materias' => []]);
        }

        // Lectura por alcance del rol sobre calificaciones.
        $alumno = Alumno::find($alumnoId);
        if (!$alumno) return response()->json(['message' => 'Alumno no encontrado'], 404);
        $this->assertCanReadCurso($req, $alumno->anio, $alumno->division, 'calificaciones');

        $anio  = $req->query('anio');
        $div   = $req->query('division');
        $ciclo = $req->query('ciclo_lectivo');

        $rows = AlumnoPromedio::query()
            ->with(['materia:id,nombre'])          // nombre legible
            ->where('alumno_id', (int)$alumnoId)
            ->when($anio,  fn($q) => $q->where('anio', (int)$anio))
            ->when($div,   fn($q) => $q->where('division', strtoupper($div)))
            ->when($ciclo, fn($q) => $q->where('ciclo_lectivo', (int)$ciclo))
            ->orderBy('materia_id')
            ->orderBy('periodo')
            ->get();

        // Agrupar por materia
        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->materia_id;
            if (!isset($out[$key])) {
                $out[$key] = [
                    'materia_id'     => (int) $r->materia_id,
                    'materia_nombre' => optional($r->materia)->nombre, // null-safe
                    'promedios'      => [],
                ];
            }
            $out[$key]['promedios'][] = [
                'periodo'     => (int) $r->periodo,                    // 0..2
                'promedio'    => is_null($r->promedio) ? null : (float) $r->promedio,
                'notas_count' => (int) $r->notas_count,
            ];
        }

        return response()->json([
            'materias' => array_values($out),
        ]);
    }
}


<?php

namespace App\Dominios\Sistema;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Calificaciones\AlumnoMateriaEstado;
use App\Dominios\Usuarios\User;
use App\Support\AuthorizesScope;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Controlador que expone endpoints para el dashboard principal.
// Proporciona metricas de rendimiento de alumnos y cursos.
class DashboardController extends Controller
{
    use AuthorizesScope;

    // Cantidad de preceptores del sistema, para la tarjeta del dashboard.
    // Endpoint aparte (y liviano) porque /admin/users no lo puede consultar un
    // profesor, y el dashboard se muestra para todos los roles.
    public function preceptores(Request $req)
    {
        if (!$req->user()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $base = User::where('role', RoleHierarchy::PRECEPTOR);

        return response()->json([
            'total'   => (clone $base)->count(),
            'activos' => (clone $base)->where('status', 'active')->count(),
        ]);
    }

    // Obtiene el top 10 de alumnos con mejor promedio y el top 10 de cursos
    // para un ciclo lectivo determinado (o el ano actual por defecto).
    public function topPerformers(Request $req)
    {
        // Reporte agregado de rendimiento: solo roles docentes/staff.
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para ver el reporte de rendimiento.');
        }

        $ciclo = $req->integer('ciclo_lectivo') ?: (int) date('Y');

        // Query: top 10 alumnos con mejor promedio, cantidad de materias y aprobadas
        $topStudents = AlumnoMateriaEstado::join('alumnos', 'alumnos.id', '=', 'alumno_materias_estado.alumno_id')
            ->select(
                'alumno_materias_estado.alumno_id',
                'alumnos.nombre',
                'alumnos.apellido',
                'alumnos.anio',
                'alumnos.division',
                DB::raw('ROUND(AVG(alumno_materias_estado.nota_final), 2) as promedio'),
                DB::raw('COUNT(*) as materias_count'),
                DB::raw("SUM(CASE WHEN alumno_materias_estado.estado_final = 'aprobada' THEN 1 ELSE 0 END) as aprobadas_count")
            )
            ->where('alumno_materias_estado.ciclo_lectivo', $ciclo)
            ->whereNotNull('alumno_materias_estado.nota_final')
            ->groupBy('alumno_materias_estado.alumno_id', 'alumnos.nombre', 'alumnos.apellido', 'alumnos.anio', 'alumnos.division')
            ->orderByDesc('promedio')
            ->limit(10);

        // Query: top 10 cursos con mejor promedio general
        $topCourses = AlumnoMateriaEstado::select(
                'anio',
                'division',
                DB::raw('ROUND(AVG(nota_final), 2) as promedio'),
                DB::raw('COUNT(DISTINCT alumno_id) as alumnos_count'),
                DB::raw('COUNT(*) as total_registros'),
                DB::raw("SUM(CASE WHEN estado_final = 'aprobada' THEN 1 ELSE 0 END) as aprobadas_count")
            )
            ->where('ciclo_lectivo', $ciclo)
            ->whereNotNull('nota_final')
            ->groupBy('anio', 'division')
            ->orderByDesc('promedio')
            ->limit(10);

        // Profesor/preceptor: solo ve sus cursos asignados.
        // Usa assignedPairs (une asignaciones de usuario y ficha profesional por email).
        if (!$this->userCanWriteAll($req)) {
            $assigns = collect($this->assignedPairs($req))
                ->filter(fn ($p) => !empty($p['anio']))
                ->map(fn ($p) => [(int) $p['anio'], $p['division'] ?? null])
                ->values();

            if ($assigns->isEmpty()) {
                return response()->json([
                    'ciclo_lectivo' => $ciclo,
                    'top_students'  => [],
                    'top_courses'   => [],
                ]);
            }

            $scopeFn = function ($q, $colPrefix = '') use ($assigns) {
                $q->where(function ($sub) use ($assigns, $colPrefix) {
                    foreach ($assigns as [$anio, $division]) {
                        $sub->orWhere(function ($w) use ($anio, $division, $colPrefix) {
                            $w->where($colPrefix . 'anio', $anio);
                            if ($division === null) {
                                $w->whereNull($colPrefix . 'division');
                            } else {
                                $w->where($colPrefix . 'division', $division);
                            }
                        });
                    }
                });
            };
            $scopeFn($topStudents, 'alumno_materias_estado.');
            $scopeFn($topCourses, '');
        }

        $students = $topStudents->get()->map(function ($s) {
            return [
                'alumno_id' => (int) $s->alumno_id,
                'nombre'    => $s->nombre,
                'apellido'  => $s->apellido,
                'curso'     => \App\Support\CourseLabel::label($s->anio, $s->division),
                'promedio'  => (float) $s->promedio,
                'materias'  => (int) $s->materias_count,
                'aprobadas' => (int) $s->aprobadas_count,
            ];
        })->values();

        $courses = $topCourses->get()->map(function ($c) {
            return [
                'anio'           => (int) $c->anio,
                'division'       => $c->division,
                'curso'          => \App\Support\CourseLabel::label($c->anio, $c->division),
                'promedio'       => (float) $c->promedio,
                'alumnos'        => (int) $c->alumnos_count,
                'total_materias' => (int) $c->total_registros,
                'aprobadas'      => (int) $c->aprobadas_count,
            ];
        })->sortByDesc('promedio')->values();

        return response()->json([
            'ciclo_lectivo' => $ciclo,
            'top_students'  => $students,
            'top_courses'   => $courses,
        ]);
    }

    // Números de las tarjetas y gráficos del panel, contados en MySQL.
    // Antes el dashboard bajaba /alumnos?per_page=2000 y /profesores?per_page=2000
    // (con direcciones, teléfonos, responsables y materias de cada alumno) y
    // contaba en el navegador: con 1200 alumnos son varios MB y varios segundos
    // por cada apertura. Acá viajan solo los totales.
    public function resumen(Request $req)
    {
        if (!$req->user()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $profesores = Profesor::query()->selectRaw("
            COUNT(*) total,
            SUM(CASE WHEN estado = 'Licencia' THEN 1 ELSE 0 END) licencia,
            SUM(CASE WHEN estado = 'Licencia' THEN 0 ELSE 1 END) activos,
            SUM(CASE WHEN suplente = 1 THEN 1 ELSE 0 END) suplentes,
            SUM(CASE WHEN suplente = 1 THEN 0 ELSE 1 END) titulares
        ")->first();

        // El padrón se rige por el mismo alcance que /alumnos; si el rol no lo
        // puede ver, el resto del resumen igual se devuelve.
        try {
            $base = Alumno::query();
            $this->scopeAlumnoIndex($req, $base);

            $rec = '(SELECT COUNT(*) FROM alumno_recursandos r WHERE r.alumno_id = alumnos.id)';
            $int = '(SELECT COUNT(*) FROM alumno_intensificaciones i WHERE i.alumno_id = alumnos.id)';

            $estados = (clone $base)->selectRaw("
                SUM(CASE WHEN $rec > 0 AND $int > 0 THEN 1 ELSE 0 END) ambos,
                SUM(CASE WHEN $rec > 0 AND $int = 0 THEN 1 ELSE 0 END) solo_rec,
                SUM(CASE WHEN $rec = 0 AND $int > 0 THEN 1 ELSE 0 END) solo_int,
                SUM(CASE WHEN $rec = 0 AND $int = 0 THEN 1 ELSE 0 END) ninguno
            ")->first();

            $alumnos = [
                'total' => (clone $base)->count(),
                'por_genero' => (clone $base)
                    ->selectRaw("COALESCE(UPPER(TRIM(genero)), '') g, COUNT(*) c")
                    ->groupBy('g')->pluck('c', 'g'),
                'por_anio' => (clone $base)
                    ->selectRaw('anio, COUNT(*) c')->groupBy('anio')->pluck('c', 'anio'),
                'por_curso' => (clone $base)
                    ->selectRaw('anio, division, COUNT(*) c')
                    ->groupBy('anio', 'division')
                    ->orderByDesc('c')
                    ->get()
                    ->map(fn ($r) => [
                        'anio' => (int) $r->anio,
                        'division' => $r->division,
                        'c' => (int) $r->c,
                    ])->values(),
                'estados' => [
                    'ambos'    => (int) ($estados->ambos ?? 0),
                    'solo_rec' => (int) ($estados->solo_rec ?? 0),
                    'solo_int' => (int) ($estados->solo_int ?? 0),
                    'ninguno'  => (int) ($estados->ninguno ?? 0),
                ],
            ];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $alumnos = null;
        }

        return response()->json([
            'alumnos' => $alumnos,
            'profesores' => [
                'total'     => (int) ($profesores->total ?? 0),
                'activos'   => (int) ($profesores->activos ?? 0),
                'licencia'  => (int) ($profesores->licencia ?? 0),
                'suplentes' => (int) ($profesores->suplentes ?? 0),
                'titulares' => (int) ($profesores->titulares ?? 0),
            ],
        ]);
    }
}


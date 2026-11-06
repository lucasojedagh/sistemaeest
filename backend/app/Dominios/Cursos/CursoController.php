<?php

namespace App\Dominios\Cursos;

use App\Http\Controllers\Controller;

use App\Dominios\Cursos\Curso;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Support\ActivityLogger;
use App\Support\AuthorizesScope;
use App\Support\SchoolYear;

// Controlador para la gestion de cursos.
// Proporciona endpoints CRUD para listar, crear, actualizar y eliminar cursos,
// incluyendo la asignacion de anio, division, turno y cupo.
class CursoController extends Controller
{
    use AuthorizesScope;
    // Lista los cursos con filtros opcionales por anio y estado activo.
    // GET /api/cursos?anio=1&activo=true
    public function index(Request $request): JsonResponse
    {
        $q = Curso::query()->orderBy('anio')->orderBy('division');

        if ($request->filled('anio')) {
            $q->where('anio', (int) $request->anio);
        }
        if ($request->filled('activo')) {
            $q->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        // El listado se recorta según el alcance de lectura de cursos del rol.
        $this->scopeCursoIndex($request, $q);

        return response()->json(
            $q->paginate(\App\Support\Paginacion::porPagina($request, 200, 500), ['id','anio','division','turno','cupo','activo'])
        );
    }

    // Crea un nuevo curso con anio, division, turno y cupo. Normaliza la division a mayuscula.
    // POST /api/cursos
    public function store(Request $request): JsonResponse
    {
        // Normalizamos división a MAYÚSCULA antes de validar
        $req = $request->all();
        if (isset($req['division'])) {
            $req['division'] = strtoupper(trim((string) $req['division']));
            $request->merge(['division' => $req['division']]);
        }

        $anio = (int) ($request->input('anio'));

        $data = $request->validate([
            'anio'     => ['required','integer','min:1','max:'.\App\Support\SchoolYear::maxYear()],
            'division' => [
                'required','string','regex:/^[0-9A-Za-z]$/',
                Rule::unique('cursos', 'division')->where(fn($q) => $q->where('anio', $anio)),
            ],
            'turno'    => ['nullable','in:manana,tarde,noche'],
            'cupo'     => ['nullable','integer','min:0'],
            'activo'   => ['boolean'],
        ]);

        // Si llega repetido con mismo (anio, division), fallará por el índice único;
        // si preferís no lanzar error y simplemente devolver el existente:
        // $curso = Curso::firstOrCreate(['anio'=>$data['anio'],'division'=>$data['division']], $data + ['activo'=>$data['activo'] ?? true]);

        $curso = Curso::create($data + ['activo' => $data['activo'] ?? true]);

        // divisiones_index (TTL 1 día) puede estar armado desde la tabla cursos.
        \Illuminate\Support\Facades\Cache::forget('divisiones_index');

        ActivityLogger::log($request, 'Creó curso ' . \App\Support\CourseLabel::label($curso->anio, $curso->division), 'curso.store', 'Curso', $curso->id);

        return response()->json($curso, 201);
    }

    // Actualiza los datos de un curso existente: turno, cupo y estado activo.
    // PATCH /api/cursos/{id}
    public function update(Request $request, Curso $curso): JsonResponse
    {
        // normalizamos división si llega (aunque en PATCH actual no se permite cambiarla)
        if ($request->has('division')) {
            $request->merge(['division' => strtoupper(trim((string)$request->division))]);
        }

        $data = $request->validate([
            'turno'  => ['nullable','in:manana,tarde,noche'],
            'cupo'   => ['nullable','integer','min:0'],
            'activo' => ['nullable','boolean'],
        ]);

        $old = $curso->toArray();
        $nuevoActivo = array_key_exists('activo', $data) ? (bool) $data['activo'] : (bool) $curso->activo;

        // Los años 1 a 7 existen siempre: no se puede desactivar la última
        // división activa de uno de esos años.
        if (!$nuevoActivo && (bool) $curso->activo && $curso->anio >= 1 && $curso->anio <= 7) {
            $quedan = Curso::where('anio', $curso->anio)
                ->where('id', '!=', $curso->id)
                ->where('activo', true)
                ->count();
            if ($quedan === 0) {
                return response()->json(
                    ['message' => 'No se puede desactivar: es la última división activa de ' . \App\Support\CourseLabel::anio($curso->anio) . ' y los 7 años deben existir.'],
                    422
                );
            }
        }

        $curso->update($data);

        \Illuminate\Support\Facades\Cache::forget('divisiones_index');

        ActivityLogger::log($request, 'Actualizó curso ' . \App\Support\CourseLabel::label($curso->anio, $curso->division), 'curso.update', 'Curso', $curso->id, $old, $curso->toArray());

        return response()->json($curso);
    }

    // Elimina un curso del sistema.
    // Solo si no dejó rastro: tiene datos de alumnos se conserva desactivando la
    // división, no borrando el curso.
    // DELETE /api/cursos/{id}
    public function destroy(Request $request, Curso $curso): JsonResponse
    {
        $this->assertPasswordConfirmed($request);

        if ($curso->activo && $curso->anio >= 1 && $curso->anio <= 7) {
            $quedan = Curso::where('anio', $curso->anio)
                ->where('id', '!=', $curso->id)
                ->where('activo', true)
                ->count();
            if ($quedan === 0) {
                return response()->json(
                    ['message' => 'No se puede eliminar: es la última división activa de ' . \App\Support\CourseLabel::anio($curso->anio) . ' y los 7 años deben existir.'],
                    422
                );
            }
        }

        // Borrar el curso se lleva por cascada su horario, las clases y la
        // asistencia de todos sus alumnos. Si hay algo de eso, la alternativa es
        // desactivar la división: desaparece de las listas y el histórico queda.
        $nAlumnos = \App\Dominios\Alumnos\Alumno::where('anio', $curso->anio)
            ->where('division', $curso->division)
            ->count();
        $nClases = \Illuminate\Support\Facades\DB::table('clases')->where('curso_id', $curso->id)->count();
        $nAsistencias = \Illuminate\Support\Facades\DB::table('asistencias')
            ->join('clases', 'clases.id', '=', 'asistencias.clase_id')
            ->where('clases.curso_id', $curso->id)
            ->count();
        $nCeldas = \Illuminate\Support\Facades\DB::table('horario_celdas')->where('curso_id', $curso->id)->count();

        if ($nAlumnos > 0 || $nClases > 0 || $nAsistencias > 0 || $nCeldas > 0) {
            $pedazos = [];
            if ($nAlumnos > 0)     $pedazos[] = $nAlumnos . ($nAlumnos === 1 ? ' alumno' : ' alumnos');
            if ($nClases > 0)      $pedazos[] = $nClases . ($nClases === 1 ? ' clase' : ' clases');
            if ($nAsistencias > 0) $pedazos[] = $nAsistencias . ($nAsistencias === 1 ? ' registro de asistencia' : ' registros de asistencia');
            if ($nCeldas > 0)      $pedazos[] = $nCeldas . ($nCeldas === 1 ? ' hora de horario' : ' horas de horario');

            return response()->json([
                'message' => 'No se puede eliminar el curso ' . \App\Support\CourseLabel::label($curso->anio, $curso->division)
                    . ': tiene ' . implode(', ', $pedazos) . ' y el sistema no borra esos datos.'
                    . ' Desactivá la división en "Editar divisiones": el curso deja de aparecer y todo se conserva.',
            ], 422);
        }

        $curso->delete();

        \Illuminate\Support\Facades\Cache::forget('divisiones_index');

        ActivityLogger::log($request, 'Eliminó curso ' . \App\Support\CourseLabel::label($curso->anio, $curso->division), 'curso.destroy', 'Curso', $curso->id);

        return response()->json(['ok' => true]);
    }
}



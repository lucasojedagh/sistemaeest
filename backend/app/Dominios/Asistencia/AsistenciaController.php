<?php

namespace App\Dominios\Asistencia;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Asistencia\Asistencia;
use App\Dominios\Asistencia\AsistenciaJustificacion;
use App\Dominios\Asistencia\Clase;
use App\Dominios\Cursos\Curso;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\ActivityLogger;
use App\Support\AsistenciaReglas;
use App\Support\AuthorizesScope;
use App\Support\CourseLabel;
use App\Support\RoleHierarchy;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsistenciaController extends Controller
{
    use AuthorizesScope;

    private static array $ESTADOS = AsistenciaReglas::ESTADOS;
    private static array $CLASE_ESTADOS = AsistenciaReglas::CLASE_ESTADOS;
    private static array $J_ESTADOS = AsistenciaReglas::J_ESTADOS;

    private function scopeCursoIds(Request $req): ?array
    {
        if ($this->userCanWriteAll($req)) {
            return null;
        }
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $assigns = $req->user()->anioDivisiones()
            ->whereNotNull('anio')
            ->get(['anio', 'division']);

        if ($assigns->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($assigns as $a) {
            $ids = array_merge($ids, Curso::where('anio', (int) $a->anio)
                ->when($a->division !== null, fn ($q) => $q->where('division', strtoupper((string) $a->division)))
                ->pluck('id')
                ->all());
        }
        return array_values(array_unique($ids));
    }

    private function assertCanOperar(Request $req, int $anio, ?string $division): void
    {
        if (!$this->userCanWriteAll($req) && !$this->userIsAssignedTo($req, $anio, $division)) {
            abort(403, 'No tenés permiso para operar sobre este curso.');
        }
    }

    private function resolverCurso(int $anio, string $division): Curso
    {
        $div = strtoupper(trim($division));
        $curso = Curso::where('anio', $anio)->where('division', $div)->first();
        if ($curso) {
            if (!$curso->activo) {
                abort(422, 'El curso ' . CourseLabel::label($anio, $div) . ' está desactivado.');
            }
            return $curso;
        }
        $curso = Curso::create(['anio' => $anio, 'division' => $div, 'activo' => true]);
        \Illuminate\Support\Facades\Cache::forget('divisiones_index');
        return $curso;
    }

    private function aplicarFiltrosFecha($q, Request $req, string $tabla)
    {
        if ($desde = $req->query('desde')) {
            $q->whereDate("{$tabla}.fecha", '>=', $desde);
        }
        if ($hasta = $req->query('hasta')) {
            $q->whereDate("{$tabla}.fecha", '<=', $hasta);
        }
        if ($year = $req->integer('year')) {
            $q->whereYear("{$tabla}.fecha", $year);
        }
        if ($mes = $req->query('mes')) {
            if (preg_match('/^\d{4}-(\d{2})$/', (string) $mes, $m) === 1) {
                $q->whereMonth("{$tabla}.fecha", (int) $m[1]);
            } elseif (ctype_digit((string) $mes)) {
                $q->whereMonth("{$tabla}.fecha", (int) $mes);
            } else {
                abort(422, 'El filtro "mes" debe ser un número (1-12) o un formato YYYY-MM.');
            }
        }
        return $q;
    }

    public function cursos(Request $req): JsonResponse
    {
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $map = [];
        if ($this->userCanWriteAll($req)) {
            $divs = \App\Dominios\Cursos\ParametroDivision::where('activo', true)
                ->orderBy('anio')->orderBy('division')
                ->get(['anio', 'division']);
            if ($divs->isEmpty()) {
                $divs = Curso::where('activo', true)->orderBy('anio')->orderBy('division')->get(['anio', 'division']);
            }
            foreach ($divs as $d) {
                $map[(int) $d->anio][] = strtoupper(trim((string) $d->division));
            }
        } else {
            $assigns = $req->user()->anioDivisiones()->whereNotNull('anio')->get(['anio', 'division']);
            foreach ($assigns as $a) {
                $map[(int) $a->anio][] = $a->division !== null ? strtoupper(trim((string) $a->division)) : '*';
            }
        }

        $normalized = [];
        foreach ($map as $k => $arr) {
            $normalized[(int) $k] = array_values(array_unique($arr));
        }
        ksort($normalized, SORT_NUMERIC);

        return response()->json($normalized);
    }

    public function index(Request $req): JsonResponse
    {
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? strtoupper(trim((string) $req->query('division', ''))) : null;

        if ($anio) {
            $this->assertCanOperar($req, $anio, $division);
        }

        $q = Clase::query()
            ->with(['curso:id,anio,division,activo', 'responsable:id,name,apellido'])
            ->with(['asistencias:id,clase_id,estado']);

        if ($anio) {
            $q->whereHas('curso', fn ($c) => $c->where('anio', $anio)->when($division, fn ($c2) => $c2->where('division', $division)));
        }

        $scopeIds = $this->scopeCursoIds($req);
        if ($scopeIds !== null) {
            $q->whereIn('curso_id', $scopeIds);
        }

        $this->aplicarFiltrosFecha($q, $req, 'clases');

        $clases = $q->orderByDesc('fecha')->orderByDesc('numero_clase')->limit(500)->get();

        $out = $clases->map(function (Clase $cl) {
            $counts = ['presente' => 0, 'ausente' => 0, 'justificado' => 0, 'tarde' => 0];
            foreach ($cl->asistencias as $a) {
                if (isset($counts[$a->estado])) {
                    $counts[$a->estado]++;
                }
            }
            return [
                'id'               => $cl->id,
                'fecha'            => $cl->fecha->toDateString(),
                'numero_clase'     => $cl->numero_clase,
                'hora_inicio'      => $cl->hora_inicio,
                'hora_fin'         => $cl->hora_fin,
                'estado'           => $cl->estado,
                'comentario'       => $cl->comentario,
                'curso'            => $cl->curso ? [
                    'id' => $cl->curso->id,
                    'anio' => $cl->curso->anio,
                    'division' => $cl->curso->division,
                    'label' => CourseLabel::label($cl->curso->anio, $cl->curso->division),
                ] : null,
                'responsable'      => $cl->responsable ? [
                    'nombre' => trim(($cl->responsable->name ?? '') . ' ' . ($cl->responsable->apellido ?? '')),
                ] : null,
                'alumnos_registrados' => (int) $cl->asistencias->count(),
                'resumen'          => $counts,
                'tomada'           => $cl->asistencias->isNotEmpty(),
            ];
        });

        return response()->json([
            'clases' => $out,
            'resumen' => [
                'dictadas' => $clases->where('estado', 'dictada')->count(),
                'suspendidas' => $clases->where('estado', 'suspendida')->count(),
                'total_asistencias' => $clases->sum(fn (Clase $c) => (int) $c->asistencias->count()),
            ],
        ]);
    }

    public function store(Request $req): JsonResponse
    {
        $data = $req->validate([
            'anio'        => ['required', 'integer', 'min:1'],
            'division'    => ['required', 'string', 'max:1'],
            'fecha'       => ['required', 'date'],
            'hora_inicio' => ['nullable', 'date_format:H:i,H:i:s'],
            'hora_fin'    => ['nullable', 'date_format:H:i,H:i:s'],
            'estado'      => ['nullable', 'string', 'in:' . implode(',', self::$CLASE_ESTADOS)],
            'comentario'  => ['nullable', 'string', 'max:2000'],
            'alumnos'     => ['nullable', 'array'],
            'alumnos.*.alumno_id' => ['required', 'integer', 'min:1'],
            'alumnos.*.estado'    => ['required', 'string', 'in:' . implode(',', self::$ESTADOS)],
            'alumnos.*.anotacion' => ['nullable', 'string', 'max:500'],
            '_detalles'   => ['nullable', 'string', 'max:1000'],
        ]);

        $anio = (int) $data['anio'];
        $division = strtoupper(trim((string) $data['division']));
        $fecha = $data['fecha'];

        $this->assertCanOperar($req, $anio, $division);

        $curso = $this->resolverCurso($anio, $division);
        $alumnosEnCurso = Alumno::where('anio', $anio)
            ->where('division', $division)
            ->where('estado', '!=', 'Egresado')
            ->pluck('id')
            ->all();

        $submit = collect($data['alumnos'] ?? []);

        $invalidos = $submit->pluck('alumno_id')->map(fn ($id) => (int) $id)
            ->diff($alumnosEnCurso)
            ->values();

        if ($invalidos->isNotEmpty()) {
            return response()->json([
                'message' => 'Algunos alumnos no pertenecen al curso ' . CourseLabel::label($anio, $division) . ' o no están activos.',
                'alumno_ids' => $invalidos,
            ], 422);
        }

        $estado = $data['estado'] ?? ($submit->isEmpty() ? 'suspendida' : 'dictada');

        $args = [$req, $curso, $fecha, $estado, $data, $submit];

        try {
            $clase = DB::transaction(function () use ($args) {
                [$req, $curso, $fecha, $estado, $data, $submit] = $args;

                $numeroClase = ((int) Clase::where('curso_id', $curso->id)->max('numero_clase')) + 1;

                $clase = Clase::create([
                    'curso_id'         => $curso->id,
                    'fecha'            => $fecha,
                    'numero_clase'     => $numeroClase,
                    'hora_inicio'      => $data['hora_inicio'] ?? null,
                    'hora_fin'         => $data['hora_fin'] ?? null,
                    'responsable_id'   => $req->user()->id,
                    'tipo_responsable' => (string) $req->user()->role,
                    'estado'           => $estado,
                    'comentario'       => isset($data['comentario']) && trim((string) $data['comentario']) !== ''
                        ? trim((string) $data['comentario'])
                        : null,
                ]);

                $rows = [];
                foreach ($submit as $al) {
                    $rows[] = [
                        'clase_id'   => $clase->id,
                        'alumno_id'  => (int) $al['alumno_id'],
                        'estado'     => $al['estado'],
                        'anotacion'  => isset($al['anotacion']) && trim((string) $al['anotacion']) !== ''
                            ? trim((string) $al['anotacion'])
                            : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (!empty($rows)) {
                    Asistencia::insert($rows);
                }

                return $clase;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $existing = Clase::where('curso_id', $curso->id)->where('fecha', $fecha)->first();
                return response()->json([
                    'message' => 'Ya existe asistencia cargada para ' . CourseLabel::label($anio, $division) . ' en la fecha ' . $fecha . '. Se puede editar la existente.',
                    'clase_id' => $existing ? $existing->id : null,
                ], 409);
            }
            throw $e;
        }

        ActivityLogger::log(
            $req,
            'Registró clase #' . $clase->numero_clase . ' de ' . CourseLabel::label($anio, $division) . ' (' . $fecha . ') con ' . $submit->count() . ' alumnos',
            'asistencia.store',
            'Clase',
            $clase->id,
            null,
            [
                'curso'   => CourseLabel::label($anio, $division),
                'fecha'   => $fecha,
                'estado'  => $estado,
                'alumnos' => $submit->count(),
            ]
        );

        return response()->json($this->claseDetalle($req, $clase->id), 201);
    }

    private function claseDetalle(Request $req, int $claseId): array
    {
        $clase = Clase::with(['curso:id,anio,division,activo', 'responsable:id,name,apellido'])
            ->with(['asistencias.alumno:id,nombre,apellido', 'asistencias.justificacion:id,asistencia_id,estado,motivo'])
            ->findOrFail($claseId);

        $alumnosCurso = Alumno::where('anio', $clase->curso->anio)
            ->where('division', $clase->curso->division)
            ->orderBy('apellido')->orderBy('nombre')
            ->get(['id', 'nombre', 'apellido']);

        $byAlumno = $clase->asistencias->keyBy('alumno_id');

        $alumnos = $alumnosCurso->map(function (Alumno $al) use ($byAlumno) {
            $a = $byAlumno->get($al->id);
            return [
                'alumno_id'   => $al->id,
                'nombre'      => $al->nombre,
                'apellido'    => $al->apellido,
                'estado'      => $a ? $a->estado : null,
                'anotacion'   => $a ? $a->anotacion : null,
                'justificacion' => $a && $a->justificacion ? [
                    'estado' => $a->justificacion->estado,
                    'motivo' => $a->justificacion->motivo,
                ] : null,
            ];
        });

        return [
            'clase' => [
                'id'           => $clase->id,
                'fecha'        => $clase->fecha->toDateString(),
                'numero_clase' => $clase->numero_clase,
                'hora_inicio'  => $clase->hora_inicio,
                'hora_fin'     => $clase->hora_fin,
                'estado'       => $clase->estado,
                'comentario'   => $clase->comentario,
                'curso'        => [
                    'anio' => $clase->curso->anio,
                    'division' => $clase->curso->division,
                    'label' => CourseLabel::label($clase->curso->anio, $clase->curso->division),
                ],
                'responsable'  => $clase->responsable ? [
                    'nombre' => trim(($clase->responsable->name ?? '') . ' ' . ($clase->responsable->apellido ?? '')),
                    'rol'    => $clase->tipo_responsable,
                ] : null,
            ],
            'alumnos' => $alumnos,
        ];
    }

    public function show(Request $req, int $claseId): JsonResponse
    {
        $clase = Clase::with('curso:id,anio,division,activo')->findOrFail($claseId);
        $this->assertCanOperar($req, $clase->curso->anio, $clase->curso->division);

        return response()->json($this->claseDetalle($req, $claseId));
    }

    /**
     * Estado de asistencia por alumno, como mapa "alumno_<id>" => estado.
     * Al ir al nivel superior del diff, el historial muestra solo el alumno que
     * se corrigio, en vez de la clase entera.
     * Acepta los registros ya guardados (coleccion indexada por alumno_id) o
     * lo que se esta por guardar (coleccion de arrays con alumno_id/estado).
     */
    private function estadosPorAlumno($origen): array
    {
        $mapa = [];
        foreach ($origen as $clave => $item) {
            if (is_array($item)) {
                $id     = (int) ($item['alumno_id'] ?? 0);
                $estado = (string) ($item['estado'] ?? '');
            } else {
                $id     = (int) ($item->alumno_id ?? $clave);
                $estado = (string) ($item->estado ?? '');
            }
            if ($id > 0 && $estado !== '') {
                $mapa['alumno_' . $id] = $estado;
            }
        }
        ksort($mapa);
        return $mapa;
    }

    public function update(Request $req, int $claseId): JsonResponse
    {
        $clase = Clase::with('curso:id,anio,division,activo')->findOrFail($claseId);
        $this->assertCanOperar($req, $clase->curso->anio, $clase->curso->division);

        $data = $req->validate([
            'hora_inicio' => ['nullable', 'date_format:H:i,H:i:s'],
            'hora_fin'    => ['nullable', 'date_format:H:i,H:i:s'],
            'estado'      => ['nullable', 'string', 'in:' . implode(',', self::$CLASE_ESTADOS)],
            'comentario'  => ['nullable', 'string', 'max:2000'],
            'alumnos'     => ['nullable', 'array'],
            'alumnos.*.alumno_id' => ['required', 'integer', 'min:1'],
            'alumnos.*.estado'    => ['required', 'string', 'in:' . implode(',', self::$ESTADOS)],
            'alumnos.*.anotacion' => ['nullable', 'string', 'max:500'],
            '_detalles'   => ['nullable', 'string', 'max:1000'],
        ]);

        $oldValues = [
            'hora_inicio' => $clase->hora_inicio,
            'hora_fin'    => $clase->hora_fin,
            'estado'      => $clase->estado,
            'comentario'  => $clase->comentario,
        ];

        // Estado de cada alumno ANTES de guardar. Se toma acá, fuera de la
        // transaccion: adentro solo vive dentro del closure y despues no
        // existe (daba "Undefined variable" al registrar la actividad).
        $estadosAntes = $this->estadosPorAlumno(
            Asistencia::where('clase_id', $clase->id)->get()->keyBy('alumno_id')
        );

        $curso = $clase->curso;
        $alumnosEnCurso = Alumno::where('anio', $curso->anio)
            ->where('division', $curso->division)
            ->where('estado', '!=', 'Egresado')
            ->pluck('id')
            ->all();

        $submit = collect($data['alumnos'] ?? []);

        $invalidos = $submit->pluck('alumno_id')->map(fn ($id) => (int) $id)
            ->diff($alumnosEnCurso)
            ->values();

        if ($invalidos->isNotEmpty()) {
            return response()->json([
                'message' => 'Algunos alumnos no pertenecen al curso ' . CourseLabel::label($curso->anio, $curso->division) . ' o no están activos.',
                'alumno_ids' => $invalidos,
            ], 422);
        }

        DB::transaction(function () use ($clase, $data, $submit, $req) {
            $clase->hora_inicio = $data['hora_inicio'] ?? $clase->hora_inicio;
            $clase->hora_fin    = $data['hora_fin'] ?? $clase->hora_fin;
            $clase->estado      = $data['estado'] ?? $clase->estado;
            $clase->comentario  = isset($data['comentario']) && trim((string) $data['comentario']) !== ''
                ? trim((string) $data['comentario'])
                : null;
            $clase->save();

            // Sincroniza manteniendo los IDs existentes para no disparar cascade
            // en asistencia_justificaciones.
            $existentes = Asistencia::where('clase_id', $clase->id)->get()->keyBy('alumno_id');
            $submittedAlumnoIds = [];

            foreach ($submit as $al) {
                $alumnoId = (int) $al['alumno_id'];
                $submittedAlumnoIds[] = $alumnoId;
                $anotacion = isset($al['anotacion']) && trim((string) $al['anotacion']) !== ''
                    ? trim((string) $al['anotacion'])
                    : null;

                if ($existentes->has($alumnoId)) {
                    $existente = $existentes->get($alumnoId);
                    $existente->estado = $al['estado'];
                    $existente->anotacion = $anotacion;
                    $existente->save();
                } else {
                    Asistencia::create([
                        'clase_id'   => $clase->id,
                        'alumno_id'  => $alumnoId,
                        'estado'     => $al['estado'],
                        'anotacion'  => $anotacion,
                    ]);
                }
            }

            // Si se omitieron alumnos previamente registrados (ej. clase suspendida), se eliminan solo los sobrantes
            $sobrantes = $existentes->keys()->diff($submittedAlumnoIds);
            if ($sobrantes->isNotEmpty()) {
                Asistencia::where('clase_id', $clase->id)->whereIn('alumno_id', $sobrantes)->delete();
            }
        });

        ActivityLogger::log(
            $req,
            'Editó asistencia de ' . CourseLabel::label($curso->anio, $curso->division),
            'asistencia.update',
            'Clase',
            $clase->id,
            $oldValues + $estadosAntes,
            [
                'hora_inicio' => $clase->hora_inicio,
                'hora_fin'    => $clase->hora_fin,
                'estado'      => $clase->estado,
                'comentario'  => $clase->comentario,
                // Sin el conteo de alumnos: como solo existe del lado nuevo,
                // el diff lo tomaba siempre como campo agregado y ensuciaba el
                // historial con "Alumnos: se estableció 6" en cada guardado.
            ] + $this->estadosPorAlumno($submit)
        );

        return response()->json($this->claseDetalle($req, $clase->id));
    }

    public function destroy(Request $req, int $claseId): JsonResponse
    {
        if (!$this->userCanWriteAll($req)) {
            abort(403, 'Solo la supervisión puede eliminar una clase registrada.');
        }

        // Borra clase + asistencias: exige contraseña reciente.
        $this->assertPasswordConfirmed($req);

        $clase = Clase::with('curso:id,anio,division')->findOrFail($claseId);
        $label = CourseLabel::label($clase->curso->anio, $clase->curso->division);
        $numero = $clase->numero_clase;
        $clase->delete();

        ActivityLogger::log($req, 'Eliminó la clase #' . $numero . ' de ' . $label, 'asistencia.destroy', 'Clase', $claseId);

        return response()->json(['ok' => true]);
    }

    public function historial(Request $req): JsonResponse
    {
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $alumnoId = $req->integer('alumno_id');
        $dni = trim((string) $req->query('dni', ''));
        $anio = $req->integer('anio');
        $division = $anio ? strtoupper(trim((string) $req->query('division', ''))) : null;
        $estado = $req->query('estado');

        // Se puede buscar por DNI (más acorde que el id interno) o por id.
        if ($dni !== '') {
            if (!preg_match('/^\d{7,8}$/', $dni)) {
                abort(422, 'El DNI debe tener 7 u 8 números.');
            }
            $alumno = Alumno::where('dni', $dni)->first();
            if (!$alumno) {
                abort(404, 'No hay ningún alumno con ese DNI.');
            }
            $this->assertCanOperar($req, (int) $alumno->anio, $alumno->division);
            $scope = ['alumno_id' => $alumno->id];
        } elseif ($alumnoId) {
            $alumno = Alumno::findOrFail($alumnoId);
            $this->assertCanOperar($req, (int) $alumno->anio, $alumno->division);
            $scope = ['alumno_id' => $alumnoId];
        } elseif ($anio) {
            $this->assertCanOperar($req, $anio, $division);
            $scope = ['anio' => $anio, 'division' => $division];
        } else {
            abort(422, 'Falta especificar un alumno o un curso.');
        }

        $q = Asistencia::query()
            ->join('clases as cl', 'cl.id', '=', 'asistencias.clase_id')
            ->join('cursos as cu', 'cu.id', '=', 'cl.curso_id')
            ->join('alumnos as al', 'al.id', '=', 'asistencias.alumno_id')
            ->select(
                'asistencias.*',
                'cl.fecha as cl_fecha',
                'cl.numero_clase as cl_numero',
                'cl.estado as cl_estado',
                'cu.anio as cu_anio',
                'cu.division as cu_division',
                'al.nombre as al_nombre',
                'al.apellido as al_apellido',
                'al.dni as al_dni'
            );

        if (isset($scope['alumno_id'])) {
            $q->where('asistencias.alumno_id', $scope['alumno_id']);
        } else {
            $q->where('cu.anio', $scope['anio'])->where('cu.division', $scope['division']);
        }

        if ($estado && in_array($estado, self::$ESTADOS, true)) {
            $q->where('asistencias.estado', $estado);
        }

        $this->aplicarFiltrosFecha($q, $req, 'cl');

        $rows = $q->orderByDesc('cl.fecha')->orderByDesc('cl.numero_clase')->limit(1000)->get();

        $detalle = $rows->map(function ($r) {
            return [
                'asistencia_id' => $r->id,
                'fecha'         => $r->cl_fecha,
                'numero_clase'  => $r->cl_numero,
                'anio'          => (int) $r->cu_anio,
                'division'      => $r->cu_division,
                'curso'         => CourseLabel::label($r->cu_anio, $r->cu_division),
                'nombre'        => $r->al_nombre,
                'apellido'      => $r->al_apellido,
                'dni'           => $r->al_dni,
                'estado'        => $r->estado,
                'anotacion'     => $r->anotacion,
            ];
        });

        $presentes = 0;
        $ausentes = 0;
        $justificados = 0;
        $tardanzas = 0;
        foreach ($rows as $r) {
            match ($r->estado) {
                'presente' => $presentes++,
                'ausente' => $ausentes++,
                'justificado' => $justificados++,
                'tarde' => $tardanzas++,
                default => null,
            };
        }

        $total = $rows->count();
        $porcentaje = AsistenciaReglas::porcentajeAsistencia($presentes, $tardanzas, $total);

        if (isset($scope['alumno_id'])) {
            $alumno = Alumno::find($scope['alumno_id']);
            return response()->json([
                'tipo' => 'alumno',
                'alumno' => [
                    'id' => $alumno->id,
                    'nombre' => $alumno->nombre,
                    'apellido' => $alumno->apellido,
                    'dni' => $alumno->dni,
                    'anio' => (int) $alumno->anio,
                    'division' => $alumno->division,
                    'curso' => CourseLabel::label($alumno->anio, $alumno->division),
                ],
                'resumen' => [
                    'clases_dictadas' => $total,
                    'presentes' => $presentes,
                    'ausentes' => $ausentes,
                    'justificados' => $justificados,
                    'tardanzas' => $tardanzas,
                    'porcentaje' => $porcentaje,
                ],
                'detalle' => $detalle,
            ]);
        }

        $groups = [];
        foreach ($rows as $r) {
            $key = (int) $r->alumno_id;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'alumno_id' => $key,
                    'nombre' => $r->al_nombre,
                    'apellido' => $r->al_apellido,
                    'dni' => $r->al_dni,
                    'clases' => 0,
                    'presentes' => 0,
                    'ausentes' => 0,
                    'justificados' => 0,
                    'tardanzas' => 0,
                ];
            }
            $groups[$key]['clases']++;
            match ($r->estado) {
                'presente' => $groups[$key]['presentes']++,
                'ausente' => $groups[$key]['ausentes']++,
                'justificado' => $groups[$key]['justificados']++,
                'tarde' => $groups[$key]['tardanzas']++,
                default => null,
            };
        }

        $alumnos = collect(array_values($groups))
            ->map(function ($g) {
                $g['porcentaje'] = AsistenciaReglas::porcentajeAsistencia($g['presentes'], $g['tardanzas'], $g['clases']);
                return $g;
            })
            ->sortByDesc('porcentaje')
            ->values();

        return response()->json([
            'tipo' => 'curso',
            'curso' => [
                'anio' => $scope['anio'],
                'division' => $scope['division'],
                'label' => CourseLabel::label($scope['anio'], $scope['division']),
            ],
            'totales' => [
                'clases_dictadas' => $total,
                'presentes' => $presentes,
                'ausentes' => $ausentes,
                'justificados' => $justificados,
                'tardanzas' => $tardanzas,
                'porcentaje' => AsistenciaReglas::porcentajeAsistencia($presentes, $tardanzas, $total),
            ],
            'alumnos' => $alumnos,
            'detalle' => $detalle,
        ]);
    }

    // Consulta base de inasistencias: los mismos joins y filtros para los
    // dos pasos (conteos y fechas), así no se duplican las reglas de alcance.
    private function queryInasistenciasBase(Request $req, int $anio, ?string $division)
    {
        $q = Asistencia::query()
            ->join('clases as cl', 'cl.id', '=', 'asistencias.clase_id')
            ->join('alumnos as al', 'al.id', '=', 'asistencias.alumno_id');

        if ($anio) {
            $q->where('al.anio', $anio)->when($division, fn ($q2) => $q2->where('al.division', $division));
        }

        $scopeIds = $this->scopeCursoIds($req);
        if ($scopeIds !== null) {
            $q->whereIn('cl.curso_id', $scopeIds);
        }

        $this->aplicarFiltrosFecha($q, $req, 'cl');

        return $q;
    }

    public function inasistencias(Request $req): JsonResponse
    {
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? strtoupper(trim((string) $req->query('division', ''))) : null;
        $minimo = max(0, (int) $req->query('minimo', 10));

        if ($anio) {
            $this->assertCanOperar($req, $anio, $division);
        }

        // Los conteos se hacen en la base. Antes se traía una fila por cada
        // registro de asistencia (alumno × clase) para contarla en PHP: con
        // 1200 alumnos y un cuatrimestre encima son más de 100.000 modelos
        // hydrated en memoria, y la pantalla tardaba varios segundos.
        $conteos = $this->queryInasistenciasBase($req, $anio, $division)
            ->selectRaw("asistencias.alumno_id,
                         MAX(al.nombre) as nombre,
                         MAX(al.apellido) as apellido,
                         MAX(al.dni) as dni,
                         MAX(al.anio) as al_anio,
                         MAX(al.division) as al_division,
                         COUNT(*) as clases,
                         SUM(CASE WHEN asistencias.estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
                         SUM(CASE WHEN asistencias.estado = 'justificado' THEN 1 ELSE 0 END) as justificados,
                         SUM(CASE WHEN asistencias.estado = 'tarde' THEN 1 ELSE 0 END) as tardanzas")
            ->groupBy('asistencias.alumno_id')
            ->orderBy('asistencias.alumno_id')
            ->get();

        $out = [];
        foreach ($conteos as $r) {
            $clases       = (int) $r->clases;
            $ausentes     = (int) $r->ausentes;
            $justificados = (int) $r->justificados;

            $porc = AsistenciaReglas::porcentajeInasistencia($ausentes, $justificados, $clases);
            if ($porc === null || $porc < $minimo) {
                continue;
            }

            $out[] = [
                'alumno_id' => (int) $r->alumno_id,
                'nombre' => $r->nombre,
                'apellido' => $r->apellido,
                'dni' => $r->dni ?? null,
                'anio' => (int) $r->al_anio,
                'division' => $r->al_division,
                'curso' => CourseLabel::label($r->al_anio, $r->al_division),
                'clases' => $clases,
                'inasistencias' => $ausentes + $justificados,
                'justificados' => $justificados,
                'tardanzas' => (int) $r->tardanzas,
                'porcentaje_inasistencia' => $porc,
            ];
        }

        usort($out, fn ($a, $b) => $b['inasistencias'] <=> $a['inasistencias']);
        $visibles = array_slice($out, 0, 200);

        // Las fechas de falta (para el corrido, la última y las 5 recientes)
        // se piden solo para los que van en pantalla, ya deduplicadas por día.
        $fechasPorAlumno = [];
        if ($visibles !== []) {
            $filas = $this->queryInasistenciasBase($req, $anio, $division)
                ->select('asistencias.alumno_id', 'cl.fecha')
                ->whereIn('asistencias.alumno_id', array_column($visibles, 'alumno_id'))
                ->whereIn('asistencias.estado', ['ausente', 'justificado'])
                ->distinct()
                ->orderBy('asistencias.alumno_id')
                ->orderBy('cl.fecha')
                ->get();

            foreach ($filas as $f) {
                $fechasPorAlumno[(int) $f->alumno_id][] = $f->fecha;
            }
        }

        foreach ($visibles as &$item) {
            $fechas = $fechasPorAlumno[$item['alumno_id']] ?? [];
            $prev = null;
            $run = 0;
            $maxConsec = 0;
            foreach ($fechas as $f) {
                $run = ($prev !== null && (strtotime($f) - strtotime($prev)) === 86400) ? $run + 1 : 1;
                $maxConsec = max($maxConsec, $run);
                $prev = $f;
            }
            $item['faltas_consecutivas'] = $maxConsec;
            $item['ultima_falta'] = count($fechas) ? end($fechas) : null;
            $item['inasistencias_recientes'] = array_slice(array_reverse($fechas), 0, 5);
        }
        unset($item);

        return response()->json([
            'minimo' => $minimo,
            'total_alumnos' => count($out),
            'alumnos' => $visibles,
        ]);
    }

    public function justificaciones(Request $req): JsonResponse
    {
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para consultar justificaciones.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? strtoupper(trim((string) $req->query('division', ''))) : null;
        $estado = $req->query('estado');

        if ($anio) {
            $this->assertCanOperar($req, $anio, $division);
        }

        $q = AsistenciaJustificacion::query()
            ->join('asistencias as a', 'a.id', '=', 'asistencia_justificaciones.asistencia_id')
            ->join('clases as cl', 'cl.id', '=', 'a.clase_id')
            ->join('cursos as cu', 'cu.id', '=', 'cl.curso_id')
            ->join('alumnos as al', 'al.id', '=', 'a.alumno_id')
            ->join('users as reg', 'reg.id', '=', 'asistencia_justificaciones.registrado_por')
            ->leftJoin('users as apr', 'apr.id', '=', 'asistencia_justificaciones.aprobado_por')
            ->select(
                'asistencia_justificaciones.*',
                'a.id as asistencia_id',
                'a.estado as estado_asistencia',
                'cl.fecha as cl_fecha',
                'cl.numero_clase as cl_numero',
                'cu.anio as cu_anio',
                'cu.division as cu_division',
                'al.id as alumno_id',
                'al.nombre as al_nombre',
                'al.apellido as al_apellido',
                'reg.name as reg_nombre',
                'reg.apellido as reg_apellido',
                'apr.name as apr_nombre',
                'apr.apellido as apr_apellido'
            )
            ->with('documento:id,nombre');

        if ($anio) {
            $q->where('cu.anio', $anio)->when($division, fn ($q2) => $q2->where('cu.division', $division));
        }

        $scopeIds = $this->scopeCursoIds($req);
        if ($scopeIds !== null) {
            $q->whereIn('cl.curso_id', $scopeIds);
        }

        if ($estado && in_array($estado, self::$J_ESTADOS, true)) {
            $q->where('asistencia_justificaciones.estado', $estado);
        }

        $this->aplicarFiltrosFecha($q, $req, 'cl');

        $rows = $q->orderByDesc('asistencia_justificaciones.created_at')->limit(500)->get();

        $out = $rows->map(function ($r) {
            return [
                'id' => $r->id,
                'asistencia_id' => $r->asistencia_id,
                'alumno' => ['id' => $r->alumno_id, 'nombre' => $r->al_nombre, 'apellido' => $r->al_apellido],
                'curso' => CourseLabel::label($r->cu_anio, $r->cu_division),
                'fecha' => $r->cl_fecha,
                'numero_clase' => $r->cl_numero,
                'estado_asistencia' => $r->estado_asistencia,
                'motivo' => $r->motivo,
                'observacion' => $r->observacion,
                'estado' => $r->estado,
                'documento' => $r->documento ? ['id' => $r->documento->id, 'nombre' => $r->documento->nombre] : null,
                'registrado_por' => trim(($r->reg_nombre ?? '') . ' ' . ($r->reg_apellido ?? '')),
                'aprobado_por' => $r->apr_nombre ? trim($r->apr_nombre . ' ' . ($r->apr_apellido ?? '')) : null,
                'created_at' => $r->created_at?->toDateTimeString(),
            ];
        });

        return response()->json(['justificaciones' => $out]);
    }

    public function storeJustificacion(Request $req): JsonResponse
    {
        $data = $req->validate([
            'asistencia_id' => ['required', 'integer', 'min:1'],
            'motivo'        => ['required', 'string', 'max:500'],
            'observacion'   => ['nullable', 'string', 'max:1000'],
            'documento_id'  => ['nullable', 'integer', 'min:1', 'exists:documentos,id'],
        ]);

        $asistencia = Asistencia::with('clase.curso:id,anio,division', 'justificacion', 'alumno:id,nombre,apellido')
            ->findOrFail($data['asistencia_id']);

        if ($asistencia->justificacion) {
            abort(422, 'Esta inasistencia ya tiene una justificación registrada.');
        }
        if ($asistencia->estado !== AsistenciaReglas::ESTADO_AUSENTE) {
            abort(422, 'Solo se pueden justificar inasistencias con estado Ausente.');
        }

        $this->assertCanOperar($req, (int) $asistencia->clase->curso->anio, $asistencia->clase->curso->division);

        $just = AsistenciaJustificacion::create([
            'asistencia_id' => $asistencia->id,
            'motivo'        => trim($data['motivo']),
            'observacion'   => isset($data['observacion']) && trim($data['observacion']) !== '' ? trim($data['observacion']) : null,
            'documento_id'  => $data['documento_id'] ?? null,
            'estado'        => AsistenciaReglas::J_ESTADO_PENDIENTE,
            'registrado_por' => $req->user()->id,
        ]);

        ActivityLogger::log(
            $req,
            'Registró justificación para ' . $asistencia->alumno->apellido . ', ' . $asistencia->alumno->nombre,
            'asistencia.justificar',
            'Asistencia',
            $asistencia->id
        );

        return response()->json(['ok' => true, 'id' => $just->id], 201);
    }

    public function updateJustificacion(Request $req, int $id): JsonResponse
    {
        $data = $req->validate([
            'estado' => ['required', 'string', 'in:' . implode(',', [AsistenciaReglas::J_ESTADO_APROBADA, AsistenciaReglas::J_ESTADO_RECHAZADA])],
        ]);

        $just = AsistenciaJustificacion::with(['asistencia.clase.curso:id,anio,division'])
            ->findOrFail($id);

        $curso = $just->asistencia->clase->curso;
        if (!$this->userCanWriteAll($req) && !($req->user()->role === 'preceptor' && $this->userIsAssignedTo($req, (int) $curso->anio, $curso->division))) {
            abort(403, 'No tenés permiso para resolver esta justificación.');
        }

        if ($just->estado !== AsistenciaReglas::J_ESTADO_PENDIENTE) {
            abort(422, 'Esta justificación ya fue resuelta.');
        }

        DB::transaction(function () use ($just, $req, $data) {
            $just->estado = $data['estado'];
            $just->aprobado_por = $req->user()->id;
            $just->save();

            if ($data['estado'] === AsistenciaReglas::J_ESTADO_APROBADA) {
                if ($just->asistencia->estado === AsistenciaReglas::ESTADO_AUSENTE) {
                    $just->asistencia->estado = AsistenciaReglas::ESTADO_JUSTIFICADO;
                    $just->asistencia->save();
                }
            } elseif ($data['estado'] === AsistenciaReglas::J_ESTADO_RECHAZADA) {
                if ($just->asistencia->estado === AsistenciaReglas::ESTADO_JUSTIFICADO) {
                    $just->asistencia->estado = AsistenciaReglas::ESTADO_AUSENTE;
                    $just->asistencia->save();
                }
            }
        });

        ActivityLogger::log($req, 'Resolvió justificación (' . AsistenciaReglas::J_ESTADO_LABELS[$data['estado']] . ')', 'asistencia.justificacion.' . $data['estado'], 'AsistenciaJustificacion', $id);

        return response()->json(['ok' => true]);
    }

    public function estadisticas(Request $req): JsonResponse
    {
        if (!RoleHierarchy::isAtLeast($req->user()->role, RoleHierarchy::PROFESOR)) {
            abort(403, 'No tenés permiso para consultar estadísticas.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? strtoupper(trim((string) $req->query('division', ''))) : null;

        if ($anio) {
            $this->assertCanOperar($req, $anio, $division);
        }

        $scopeIds = $this->scopeCursoIds($req);

        // Los mismos filtros de siempre, resueltos con agregados en la base.
        // Antes se leían TODAS las filas de asistencia del período (dos veces:
        // la base general y el ranking de cursos) y se contaban una por una en
        // PHP: con 1200 alumnos y un ciclo lectivo completo son cientos de
        // miles de registros en memoria para devolver unas decenas de números.
        $filtrar = function ($q, bool $aplicaAnio = true) use ($req, $anio, $division, $scopeIds) {
            $q->join('clases as cl', 'cl.id', '=', 'asistencias.clase_id')
                ->join('cursos as cu', 'cu.id', '=', 'cl.curso_id');
            if ($anio && $aplicaAnio) {
                $q->where('cu.anio', $anio)->when($division, fn ($q2) => $q2->where('cu.division', $division));
            }
            if ($scopeIds !== null) {
                $q->whereIn('cl.curso_id', $scopeIds);
            }
            $this->aplicarFiltrosFecha($q, $req, 'cl');
            return $q;
        };

        $porEstado = "
            SUM(CASE WHEN asistencias.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
            SUM(CASE WHEN asistencias.estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
            SUM(CASE WHEN asistencias.estado = 'justificado' THEN 1 ELSE 0 END) as justificados,
            SUM(CASE WHEN asistencias.estado = 'tarde' THEN 1 ELSE 0 END) as tardanzas
        ";

        $fila = $filtrar(Asistencia::query())
            ->selectRaw("COUNT(*) as registros, $porEstado,
                         COUNT(DISTINCT cl.id) as clases_dictadas,
                         COUNT(DISTINCT asistencias.alumno_id) as alumnos_registrados")
            ->first();

        $counts = [
            'presente'    => (int) ($fila->presentes ?? 0),
            'ausente'     => (int) ($fila->ausentes ?? 0),
            'justificado' => (int) ($fila->justificados ?? 0),
            'tarde'       => (int) ($fila->tardanzas ?? 0),
        ];
        $totales            = array_sum($counts);
        $clasesDictadas     = (int) ($fila->clases_dictadas ?? 0);
        $alumnosRegistrados = (int) ($fila->alumnos_registrados ?? 0);

        $porcentajeGeneral = AsistenciaReglas::porcentajeAsistencia($counts['presente'], $counts['tarde'], $totales);

        $alumnosTotales = $this->totalAlumnosScope($req, $anio, $division, $scopeIds);

        // Ranking GLOBAL de cursos: no se limita al curso seleccionado, sino a
        // todos los del ámbito del usuario en el mismo período (los demás que
        // cumplan la condición, como se pide en Estadísticas).
        $porCursoGlobal = $filtrar(Asistencia::query(), false)
            ->selectRaw("cl.curso_id, MAX(cu.anio) as anio, MAX(cu.division) as division,
                         COUNT(*) as registros, $porEstado")
            ->groupBy('cl.curso_id')
            ->orderBy('cl.curso_id')
            ->get()
            ->map(function ($r) {
                $presentes = (int) $r->presentes;
                $tardanzas = (int) $r->tardanzas;
                $total     = (int) $r->registros;
                return [
                    'anio' => (int) $r->anio,
                    'division' => $r->division,
                    'curso' => CourseLabel::label($r->anio, $r->division),
                    'registros' => $total,
                    'presentes' => $presentes + $tardanzas,
                    'porcentaje' => AsistenciaReglas::porcentajeAsistencia($presentes, $tardanzas, $total),
                ];
            });

        // Los top 5 se ordenan por porcentaje y, ante igualdad, por curso o
        // alumno: así el listado no cambia según el orden en que la BD devuelve
        // las filas (el criterio de fondo es el mismo de siempre).
        $cursosOrdenados = $porCursoGlobal->sortBy(fn ($c) => sprintf('%02d|%s', (int) $c['anio'], (string) $c['division']));
        $mejoresCursos = $cursosOrdenados->sortByDesc('porcentaje')->take(5)->values();
        $peoresCursos = $cursosOrdenados->sortBy('porcentaje')->take(5)->values();

        $porAlumno = $filtrar(Asistencia::query())
            ->join('alumnos as al', 'al.id', '=', 'asistencias.alumno_id')
            ->selectRaw("asistencias.alumno_id,
                         MAX(al.nombre) as nombre, MAX(al.apellido) as apellido,
                         MAX(al.anio) as al_anio, MAX(al.division) as al_division,
                         COUNT(*) as registros, $porEstado")
            ->groupBy('asistencias.alumno_id')
            ->orderBy('asistencias.alumno_id')
            ->get()
            ->map(function ($r) {
                $presentes = (int) $r->presentes;
                $tardanzas = (int) $r->tardanzas;
                // Tarde computa como presencia y justificado como falta
                // (ver AsistenciaReglas).
                $faltas    = (int) $r->ausentes + (int) $r->justificados;
                $total     = (int) $r->registros;
                return [
                    'alumno_id' => (int) $r->alumno_id,
                    'nombre' => $r->nombre,
                    'apellido' => $r->apellido,
                    'label' => trim(($r->apellido ?? '') . ', ' . ($r->nombre ?? '')),
                    'curso' => CourseLabel::label($r->al_anio, $r->al_division),
                    'registros' => $total,
                    'presentes' => $presentes + $tardanzas,
                    'faltas' => $faltas,
                    'porcentaje' => AsistenciaReglas::porcentajeAsistencia($presentes, $tardanzas, $total),
                ];
            });

        $alumnosOrdenados = $porAlumno->sortBy('alumno_id');
        $mejoresAlumnos = $alumnosOrdenados->sortByDesc('porcentaje')->take(5)->values();
        $peoresAlumnos = $alumnosOrdenados->sortByDesc('faltas')->take(5)->values();

        $nombresMes = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $meses = $filtrar(Asistencia::query())
            ->selectRaw('DATE_FORMAT(cl.fecha, "%Y-%m") as mes, COUNT(*) as registros, COUNT(DISTINCT cl.id) as clases,' . $porEstado)
            ->groupByRaw('DATE_FORMAT(cl.fecha, "%Y-%m")')
            ->orderByRaw('DATE_FORMAT(cl.fecha, "%Y-%m")')
            ->get()
            ->map(function ($r) use ($nombresMes) {
                $mes = (string) $r->mes;
                $mesNum = (int) substr($mes, 5, 2);
                return [
                    'mes' => $mes,
                    'label' => $nombresMes[$mesNum] ?? $mes,
                    'clases' => (int) $r->clases,
                    'presentes' => (int) $r->presentes,
                    'ausentes' => (int) $r->ausentes,
                    'justificados' => (int) $r->justificados,
                    'tardanzas' => (int) $r->tardanzas,
                    'porcentaje' => AsistenciaReglas::porcentajeAsistencia((int) $r->presentes, (int) $r->tardanzas, (int) $r->registros),
                ];
            });

        return response()->json([
            'totales' => [
                'clases' => $clasesDictadas,
                'asistencias' => $totales,
                'presente' => $counts['presente'],
                'ausente' => $counts['ausente'],
                'justificado' => $counts['justificado'],
                'tarde' => $counts['tarde'],
                'alumnos_totales' => $alumnosTotales,
                'alumnos_registrados' => $alumnosRegistrados,
                'porcentaje' => $porcentajeGeneral,
            ],
            'mejores_cursos' => $mejoresCursos,
            'peores_cursos' => $peoresCursos,
            'mejores_alumnos' => $mejoresAlumnos,
            'peores_alumnos' => $peoresAlumnos,
            'evolucion_mensual' => $meses,
        ]);
    }

    private function totalAlumnosScope(Request $req, ?int $anio, ?string $division, ?array $cursoIds): int
    {
        $q = Alumno::where('estado', '!=', 'Egresado');
        if ($anio) {
            $q->where('anio', $anio)->when($division, fn ($q2) => $q2->where('division', $division));
            return (int) $q->count();
        }
        if ($cursoIds === null) {
            return (int) $q->whereNotNull('anio')->whereNotNull('division')->count();
        }
        if (empty($cursoIds)) {
            return 0;
        }
        $cursos = Curso::whereIn('id', $cursoIds)->get(['anio', 'division']);
        if ($cursos->isEmpty()) {
            return 0;
        }
        return (int) $q->where(function ($sub) use ($cursos) {
            foreach ($cursos as $c) {
                $sub->orWhere(fn ($w) => $w->where('anio', (int) $c->anio)->where('division', strtoupper((string) $c->division)));
            }
        })->count();
    }

    // Planilla imprimible de una clase ya guardada (con columnas P/A y firma).
    // GET /print/asistencia?clase_id=[&download=1]
    // Requiere autenticación (middleware auth.token: Bearer o ?ticket=) con alcance al curso de la clase.
    public function printPlanillaDia(Request $req)
    {
        $user = $req->user();
        if (!$user) {
            abort(401, 'No autorizado. Iniciá sesión para ver la planilla.');
        }

        $claseId = (int) $req->query('clase_id', 0);
        if (!$claseId) {
            abort(422, 'Falta el parámetro: clase_id');
        }

        $clase = Clase::with('curso:id,anio,division')->findOrFail($claseId);

        $req->setUserResolver(fn() => $user);
        $this->assertCanOperar($req, (int) $clase->curso->anio, $clase->curso->division);

        $filas = Asistencia::where('clase_id', $clase->id)
            ->join('alumnos as al', 'al.id', '=', 'asistencias.alumno_id')
            ->orderBy('al.apellido')->orderBy('al.nombre')
            ->get([
                'asistencias.estado',
                'asistencias.anotacion',
                'al.apellido',
                'al.nombre',
                'al.dni',
            ]);

        $viewData = [
            'curso'   => CourseLabel::label($clase->curso->anio, $clase->curso->division),
            'fecha'   => $clase->fecha ? $clase->fecha->format('d/m/Y') : '',
            'numero'  => $clase->numero_clase,
            'estado'  => $clase->estado,
            'filas'   => $filas,
        ];

        if ($req->query('download') === '1') {
            $html = view('asistencia.planilla', $viewData)->render();
            return Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->download("asistencia_{$clase->id}.pdf");
        }

        return response()->view('asistencia.planilla', $viewData);
    }
}
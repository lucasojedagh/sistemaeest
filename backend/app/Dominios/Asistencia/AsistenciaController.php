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
use App\Support\Alcances;
use App\Support\AsistenciaReglas;
use App\Support\AuthorizesScope;
use App\Support\CourseLabel;
use App\Support\Permisos;
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

    // Cuántas filas trae como máximo cada listado. Son un seguro para el server
    // con 1200 alumnos, no un capricho: cuando la realidad pasa de este número
    // la respuesta lo avisa ('recortado') para que la pantalla lo diga.
    private const TOPES = [
        'clases' => 500,
        'historial' => 1000,
        'inasistencias' => 200,
        'justificaciones' => 500,
    ];

    /** Justificaciones cargadas sobre las marcas de una clase. */
    private function countJustificadasDeClase(int $claseId): int
    {
        return DB::table('asistencia_justificaciones')
            ->join('asistencias', 'asistencias.id', '=', 'asistencia_justificaciones.asistencia_id')
            ->where('asistencias.clase_id', $claseId)
            ->count();
    }

    /** Mensaje común: una clase con papeles encima no se borra ni se suspende. */
    private function avisoJustificadas(int $numero, string $label, int $cuantas, string $accion): string
    {
        return "La clase #{$numero} de {$label} tiene {$cuantas} justificaci"
            . ($cuantas === 1 ? 'ón cargada' : 'ones cargadas')
            . ". No se hace la {$accion} para no perder esos papeles: "
            . ($cuantas === 1
                ? 'primero rechazá o borrá esa justificación.'
                : 'primero rechazá o borrá esas justificaciones.');
    }

    /**
     * Ids de curso visibles para el usuario según el alcance de lectura de
     * asistencia. null = todos los cursos; lista vacía = no ve ninguno.
     */
    private function scopeCursoIds(Request $req): ?array
    {
        $alcance = $this->alcanceVer($req, 'asistencia');
        if ($alcance === Alcances::TODO) {
            return null;
        }
        if ($alcance === Alcances::NADA) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $ids = [];
        foreach ($this->assignedPairs($req) as $a) {
            if (empty($a['anio'])) {
                continue;
            }
            $ids = array_merge($ids, Curso::where('anio', (int) $a['anio'])
                ->when($a['division'] !== null, fn ($q) => $q->where('division', $a['division']))
                ->pluck('id')
                ->all());
        }
        return array_values(array_unique($ids));
    }

    private function assertCanOperar(Request $req, int $anio, ?string $division): void
    {
        $this->assertPuedeEditarCurso($req, $anio, $division, 'asistencia');
    }

    private function assertCanVerCurso(Request $req, int $anio, ?string $division): void
    {
        $this->assertPuedeVerCurso($req, $anio, $division, 'asistencia');
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
            if (preg_match('/^(\d{4})-(\d{2})$/', (string) $mes, $m) === 1) {
                // El formato YYYY-MM trae el año: hay que filtrar también por año,
                // si no "noviembre 2024" devuelve las clases de noviembre de este año.
                $q->whereYear("{$tabla}.fecha", (int) $m[1])
                  ->whereMonth("{$tabla}.fecha", (int) $m[2]);
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
        if (!$this->puedeVer($req, 'asistencia')) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $map = [];
        if ($this->veTodo($req, 'asistencia')) {
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
            foreach ($this->assignedPairs($req) as $a) {
                if (empty($a['anio'])) {
                    continue;
                }
                $map[(int) $a['anio']][] = $a['division'] ?? '*';
            }
        }

        $normalized = [];
        foreach ($map as $k => $arr) {
            $normalized[(int) $k] = array_values(array_unique($arr));
        }
        ksort($normalized, SORT_NUMERIC);

        // Qué cursos puede efectivamente marcar esta cuenta. El preceptor ve la
        // asistencia de todo el colegio pero escribe sólo en sus cursos: sin esta
        // lista el selector ofrece cursos que después devuelven 403 al guardar,
        // y el aviso recién aparece después de marcar los 30 alumnos.
        // null = puede en todos (no se agrega la clave).
        $puedeAccion = Permisos::puede($this->rolDe($req), 'asistencia.editar');
        $alcanceEditar = $this->alcanceEditar($req, 'asistencia');
        if (!$puedeAccion || $alcanceEditar === Alcances::NADA) {
            // Sin acción o sin alcance la lista va vacía a propósito: si se omite,
            // el frontend la lee como "todos" y habilita botones que devuelven 403.
            // 'puede_marcar' distingue al rol que no toma asistencia de la cuenta
            // que sí la toma pero no tiene ningún curso asignado.
            $normalized['editables'] = [];
            $normalized['puede_marcar'] = false;
        } elseif ($alcanceEditar === Alcances::ASIGNADO) {
            $editables = [];
            foreach ($this->assignedPairs($req) as $a) {
                if (empty($a['anio'])) continue;
                $editables[] = [(int) $a['anio'], $a['division']];
            }
            $normalized['editables'] = $editables;
        }

        return response()->json($normalized);
    }

    public function index(Request $req): JsonResponse
    {
        if (!$this->puedeVer($req, 'asistencia')) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? (strtoupper(trim((string) $req->query('division', ''))) ?: null) : null;

        if ($anio) {
            $this->assertCanVerCurso($req, $anio, $division);
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

        $topeClases = self::TOPES['clases'];
        $totalClases = (clone $q)->count();
        $clases = $q->orderByDesc('fecha')->orderByDesc('numero_clase')->limit($topeClases)->get();

        // El resumen lo arma MySQL sobre todas las clases del filtro. Antes se
        // contaba sobre $clases, que está topada, así que con más de 500 clases
        // "Clases dictadas" y el promedio del mes salían calculados a medias.
        $porEstado = $q->clone()->getQuery()
            ->reorder()
            ->selectRaw('clases.estado AS estado, COUNT(*) AS cantidad')
            ->groupBy('clases.estado')
            ->get()
            ->pluck('cantidad', 'estado');

        $marcas = $q->clone()->getQuery()
            ->reorder()
            ->leftJoin('asistencias', 'asistencias.clase_id', '=', 'clases.id')
            ->selectRaw(<<<'SQL'
                SUM(asistencias.id IS NOT NULL) AS marcados,
                SUM(asistencias.estado = 'presente') AS presentes,
                SUM(asistencias.estado = 'tarde') AS tardanzas
            SQL)
            ->first();

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
                'dictadas' => (int) ($porEstado['dictada'] ?? 0),
                'suspendidas' => (int) ($porEstado['suspendida'] ?? 0),
                'total_asistencias' => (int) ($marcas->marcados ?? 0),
                'presentes' => (int) ($marcas->presentes ?? 0),
                'tardanzas' => (int) ($marcas->tardanzas ?? 0),
            ],
            'total_clases' => $totalClases,
            'recortado' => $totalClases > $topeClases,
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
            ->cursando()
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
        $marcasGuardadas = $estado === 'suspendida' ? 0 : $submit->count();

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

                // Una clase suspendida no tiene marcas: si alguien manda la
                // planilla y la deja suspendida, las marcas no entran.
                $rows = [];
                if ($estado !== 'suspendida') {
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
            'Registró clase #' . $clase->numero_clase . ' de ' . CourseLabel::label($anio, $division) . ' (' . $fecha . ') con ' . $marcasGuardadas . ' marcas',
            'asistencia.store',
            'Clase',
            $clase->id,
            null,
            [
                'curso'   => CourseLabel::label($anio, $division),
                'fecha'   => $fecha,
                'estado'  => $estado,
                'marcas'  => $marcasGuardadas,
            ]
        );

        return response()->json($this->claseDetalle($req, $clase->id), 201);
    }

    private function claseDetalle(Request $req, int $claseId): array
    {
        $clase = Clase::with(['curso:id,anio,division,activo', 'responsable:id,name,apellido'])
            ->with(['asistencias.alumno:id,nombre,apellido,anio,division', 'asistencias.justificacion:id,asistencia_id,estado,motivo'])
            ->findOrFail($claseId);

        // La planilla se arma con los alumnos que cursan: un egresado o un
        // inactivo sigue ligado al curso por sus datos históricos, pero no
        // tiene que aparecer para tomar lista.
        $alumnosCurso = Alumno::where('anio', $clase->curso->anio)
            ->where('division', $clase->curso->division)
            ->cursando()
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

        // Marcas de alumnos que hoy no están en el curso de la clase (promovidos
        // o cambiados de división). La planilla se arma con los alumnos actuales,
        // así que sin este aviso la clase parece vacía aunque el listado cuente marcas.
        $marcasFuera = $clase->asistencias
            ->filter(function ($a) use ($clase) {
                $al = $a->alumno;
                if (!$al) return true;
                return (int) $al->anio !== (int) $clase->curso->anio
                    || strtoupper(trim((string) $al->division)) !== strtoupper(trim((string) $clase->curso->division));
            })
            ->map(fn ($a) => [
                'alumno_id'    => (int) $a->alumno_id,
                // Sin el alumno (baja definitiva) no hay apellido ni nombre: armar
                // la cadena igual dejaba una coma suelta como único nombre.
                'nombre'       => $a->alumno
                    ? trim($a->alumno->apellido . ', ' . $a->alumno->nombre)
                    : 'Alumno n.° ' . $a->alumno_id,
                'estado'       => $a->estado,
                'curso_actual' => $a->alumno ? CourseLabel::label($a->alumno->anio, $a->alumno->division) : 'ya no está en el padrón',
            ])
            ->values();

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
            'marcas_fuera' => $marcasFuera,
        ];
    }

    public function show(Request $req, int $claseId): JsonResponse
    {
        $clase = Clase::with('curso:id,anio,division,activo')->findOrFail($claseId);
        // Ver el detalle de una clase es lectura: el preceptor ve todas aunque
        // sólo pueda marcar las de sus cursos asignados.
        $this->assertCanVerCurso($req, $clase->curso->anio, $clase->curso->division);

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
            ->cursando()
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

        // Una clase suspendida no puede tener marcas: son lo que se suspende.
        // Antes las marcas quedaban guardadas igual y seguían contando en el
        // Historial, Inasistencias y Estadísticas con la clase "suspendida".
        $quedaSuspendida = ($data['estado'] ?? $clase->estado) === 'suspendida';
        if ($quedaSuspendida) {
            $justificadas = $this->countJustificadasDeClase($clase->id);
            if ($justificadas > 0) {
                abort(422, $this->avisoJustificadas(
                    $clase->numero_clase,
                    CourseLabel::label($curso->anio, $curso->division),
                    $justificadas,
                    'suspensión'
                ));
            }
        }

        DB::transaction(function () use ($clase, $data, $submit, $quedaSuspendida) {
            foreach (['hora_inicio', 'hora_fin'] as $campoHora) {
                // null explícito significa "sin horario". Con ?? el valor viejo
                // no se podía borrar nunca, aunque la pantalla lo mandara vacío.
                if (array_key_exists($campoHora, $data)) {
                    $clase->$campoHora = $data[$campoHora];
                }
            }
            $clase->estado      = $data['estado'] ?? $clase->estado;
            $clase->comentario  = isset($data['comentario']) && trim((string) $data['comentario']) !== ''
                ? trim((string) $data['comentario'])
                : null;
            $clase->save();

            if ($quedaSuspendida) {
                Asistencia::where('clase_id', $clase->id)->delete();
                return;
            }

            // Sincroniza manteniendo los IDs existentes para no disparar cascade
            // en asistencia_justificaciones. Las filas de alumnos que ya no
            // pertenecen al curso NO se borran: son el historial del día, y como
            // el detalle se arma desde la nómina actual no llegan a mostrarse.
            $existentes = Asistencia::where('clase_id', $clase->id)->get()->keyBy('alumno_id');

            foreach ($submit as $al) {
                $alumnoId = (int) $al['alumno_id'];
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
        $clase = Clase::with('curso:id,anio,division')->findOrFail($claseId);
        $label = CourseLabel::label($clase->curso->anio, $clase->curso->division);
        $numero = $clase->numero_clase;

        // Eliminar una clase ya registrada es una acción de supervisión: exige
        // alcance de escritura total sobre asistencia (configurable por rol).
        // La excepción es el dueño de la clase: sin esto, el "Deshacer" que la
        // propia pantalla ofrece al guardar una lista nunca le funcionaba a un
        // preceptor (creaba la clase y el servidor le respondía 403).
        if (!$this->editaTodo($req, 'asistencia')) {
            $esSuya = (int) $clase->responsable_id === (int) $req->user()->id;
            if (!$esSuya) {
                abort(403, 'Solo la supervisión puede eliminar una clase registrada.');
            }
            $this->assertCanOperar($req, (int) $clase->curso->anio, $clase->curso->division);
        }

        // Borra clase + asistencias: exige contraseña reciente.
        $this->assertPasswordConfirmed($req);

        // Las justificaciones cuelgan de la asistencia y se van con ella por
        // cascada (documento incluido). Una clase justificada no se borra:
        // se edita y se marca suspendida.
        $justificadas = $this->countJustificadasDeClase($claseId);
        if ($justificadas > 0) {
            return response()->json([
                'message' => $this->avisoJustificadas($numero, $label, $justificadas, 'eliminación'),
            ], 422);
        }

        $clase->delete();

        ActivityLogger::log($req, 'Eliminó la clase #' . $numero . ' de ' . $label, 'asistencia.destroy', 'Clase', $claseId);

        return response()->json(['ok' => true]);
    }

    public function historial(Request $req): JsonResponse
    {
        if (!$this->puedeVer($req, 'asistencia')) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $alumnoId = $req->integer('alumno_id');
        $dni = trim((string) $req->query('dni', ''));
        $anio = $req->integer('anio');
        $division = $anio ? (strtoupper(trim((string) $req->query('division', ''))) ?: null) : null;
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
            $this->assertCanVerCurso($req, (int) $alumno->anio, $alumno->division);
            $scope = ['alumno_id' => $alumno->id];
        } elseif ($alumnoId) {
            $alumno = Alumno::findOrFail($alumnoId);
            $this->assertCanVerCurso($req, (int) $alumno->anio, $alumno->division);
            $scope = ['alumno_id' => $alumnoId];
        } elseif ($anio) {
            $this->assertCanVerCurso($req, $anio, $division);
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
            $q->where('cu.anio', $scope['anio']);
            // Division vacia significa "el ano completo", no una division sin
            // nombre: filtrar por la cadena vacia devolvia cero filas.
            if ($scope['division'] !== null) {
                $q->where('cu.division', $scope['division']);
            }
        }

        if ($estado && in_array($estado, self::$ESTADOS, true)) {
            $q->where('asistencias.estado', $estado);
        }

        $this->aplicarFiltrosFecha($q, $req, 'cl');

        $topeHistorial = self::TOPES['historial'];
        $totalFilas = (clone $q)->count();
        $rows = $q->orderByDesc('cl.fecha')->orderByDesc('cl.numero_clase')->limit($topeHistorial)->get();
        $recortado = $totalFilas > $topeHistorial;

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

        // Totales y porcentajes los calcula MySQL sobre TODAS las filas del filtro.
        // Se hacían sobre $rows, que está cortada a $topeHistorial: en un curso con
        // 120 clases el número que veía la escuela correspondía solo a las últimas
        // 1000 marcas, así que un alumno con muchas faltas temprano aparecía con
        // 95-100 % de asistencia. El corte sigue existiendo, pero sólo para el
        // detalle de abajo.
        $agregados = (clone $q)
            ->reorder()
            // selectRaw se suma a las columnas que ya trae $q para el detalle.
            // Sin limpiar, el GROUP BY arrastra asistencias.* y MySQL corta con
            // el error 1055 (only_full_group_by): la pestaña entera daba 500.
            ->select([])
            ->selectRaw(<<<'SQL'
                asistencias.alumno_id AS alumno_id,
                al.apellido AS apellido,
                al.nombre AS nombre,
                al.dni AS dni,
                COUNT(*) AS clases,
                SUM(asistencias.estado = 'presente') AS presentes,
                SUM(asistencias.estado = 'ausente') AS ausentes,
                SUM(asistencias.estado = 'justificado') AS justificados,
                SUM(asistencias.estado = 'tarde') AS tardanzas
            SQL)
            ->groupBy('asistencias.alumno_id', 'al.apellido', 'al.nombre', 'al.dni')
            ->get();

        $total = 0;
        $presentes = 0;
        $ausentes = 0;
        $justificados = 0;
        $tardanzas = 0;
        $alumnos = [];
        foreach ($agregados as $g) {
            $fila = [
                'alumno_id'    => (int) $g->alumno_id,
                'nombre'       => $g->nombre,
                'apellido'     => $g->apellido,
                'dni'          => $g->dni,
                'clases'       => (int) $g->clases,
                'presentes'    => (int) $g->presentes,
                'ausentes'     => (int) $g->ausentes,
                'justificados' => (int) $g->justificados,
                'tardanzas'    => (int) $g->tardanzas,
            ];
            $fila['porcentaje'] = AsistenciaReglas::porcentajeAsistencia(
                $fila['presentes'],
                $fila['tardanzas'],
                $fila['clases']
            );
            $total        += $fila['clases'];
            $presentes    += $fila['presentes'];
            $ausentes     += $fila['ausentes'];
            $justificados += $fila['justificados'];
            $tardanzas    += $fila['tardanzas'];
            $alumnos[] = $fila;
        }

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
                'total_filas' => $totalFilas,
                'recortado' => $recortado,
            ]);
        }

        $alumnos = collect($alumnos)->sortByDesc('porcentaje')->values()->all();

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
            'total_filas' => $totalFilas,
            'recortado' => $recortado,
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
        if (!$this->puedeVer($req, 'asistencia')) {
            abort(403, 'No tenés permiso para consultar asistencias.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? (strtoupper(trim((string) $req->query('division', ''))) ?: null) : null;
        $minimo = max(0, (int) $req->query('minimo', 10));

        if ($anio) {
            $this->assertCanVerCurso($req, $anio, $division);
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

        // Orden: primero el porcentaje (lo que lee la dirección), y dentro del
        // mismo porcentaje la cantidad de faltas como desempate.
        usort($out, function ($a, $b) {
            $r = $b['porcentaje_inasistencia'] <=> $a['porcentaje_inasistencia'];

            return $r !== 0 ? $r : $b['inasistencias'] <=> $a['inasistencias'];
        });
        $topeInas = self::TOPES['inasistencias'];
        $visibles = array_slice($out, 0, $topeInas);

        // Las fechas de falta (para el corrido, la última y las 5 recientes)
        // se piden solo para los que van en pantalla, ya deduplicadas por día.
        $fechasPorAlumno = [];
        $asistioPorAlumno = [];
        if ($visibles !== []) {
            $ids = array_column($visibles, 'alumno_id');
            $filas = $this->queryInasistenciasBase($req, $anio, $division)
                ->select('asistencias.alumno_id', 'cl.fecha')
                ->whereIn('asistencias.alumno_id', $ids)
                ->whereIn('asistencias.estado', ['ausente', 'justificado'])
                ->distinct()
                ->orderBy('asistencias.alumno_id')
                ->orderBy('cl.fecha')
                ->get();

            foreach ($filas as $f) {
                $fechasPorAlumno[(int) $f->alumno_id][] = (string) $f->fecha;
            }

            // Día de la última clase a la que sí llegó (presente o tarde). Sirve
            // de corte: las fechas de falta posteriores son su racha actual.
            $asistio = $this->queryInasistenciasBase($req, $anio, $division)
                ->selectRaw('asistencias.alumno_id, MAX(cl.fecha) as ultimo_dia')
                ->whereIn('asistencias.alumno_id', $ids)
                ->whereIn('asistencias.estado', ['presente', 'tarde'])
                ->groupBy('asistencias.alumno_id')
                ->get();

            foreach ($asistio as $a) {
                $asistioPorAlumno[(int) $a->alumno_id] = (string) $a->ultimo_dia;
            }
        }

        foreach ($visibles as &$item) {
            $fechas = $fechasPorAlumno[$item['alumno_id']] ?? [];
            $ultimoAsistio = $asistioPorAlumno[$item['alumno_id']] ?? null;
            $alHilo = $ultimoAsistio === null
                ? count($fechas)
                : count(array_filter($fechas, fn ($f) => $f > $ultimoAsistio));
            $item['faltas_consecutivas'] = $alHilo;
            $item['ultima_falta'] = count($fechas) ? end($fechas) : null;
            $item['inasistencias_recientes'] = array_slice(array_reverse($fechas), 0, 5);
        }
        unset($item);

        return response()->json([
            'minimo' => $minimo,
            'total_alumnos' => count($out),
            'alumnos' => $visibles,
            'recortado' => count($out) > $topeInas,
        ]);
    }

    public function justificaciones(Request $req): JsonResponse
    {
        if (!$this->puedeVer($req, 'asistencia')) {
            abort(403, 'No tenés permiso para consultar justificaciones.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? (strtoupper(trim((string) $req->query('division', ''))) ?: null) : null;
        $estado = $req->query('estado');

        if ($anio) {
            $this->assertCanVerCurso($req, $anio, $division);
        }

        $q = AsistenciaJustificacion::query()
            ->join('asistencias as a', 'a.id', '=', 'asistencia_justificaciones.asistencia_id')
            ->join('clases as cl', 'cl.id', '=', 'a.clase_id')
            ->join('cursos as cu', 'cu.id', '=', 'cl.curso_id')
            ->join('alumnos as al', 'al.id', '=', 'a.alumno_id')
            ->leftJoin('users as reg', 'reg.id', '=', 'asistencia_justificaciones.registrado_por')
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

        $topeJust = self::TOPES['justificaciones'];
        $totalJust = (clone $q)->count();
        $rows = $q->orderByDesc('asistencia_justificaciones.created_at')->limit($topeJust)->get();

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
                'registrado_por' => trim(($r->reg_nombre ?? '') . ' ' . ($r->reg_apellido ?? '')) ?: null,
                'aprobado_por' => $r->apr_nombre ? trim($r->apr_nombre . ' ' . ($r->apr_apellido ?? '')) : null,
                'created_at' => $r->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'justificaciones' => $out,
            'total_justificaciones' => $totalJust,
            'recortado' => $totalJust > $topeJust,
        ]);
    }

    public function storeJustificacion(Request $req): JsonResponse
    {
        $data = $req->validate([
            'asistencia_id' => ['required', 'integer', 'min:1'],
            'motivo'        => ['required', 'string', 'max:255'],
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
        // Resolver una justificación pide el permiso específico y, además, que el
        // curso esté dentro del alcance de escritura del rol.
        if (!Permisos::puede($this->rolDe($req), 'asistencia.justificar')) {
            abort(403, 'No tenés permiso para resolver esta justificación.');
        }
        $this->assertPuedeEditarCurso($req, (int) $curso->anio, $curso->division, 'asistencia');

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
        if (!$this->puedeVer($req, 'asistencia')) {
            abort(403, 'No tenés permiso para consultar estadísticas.');
        }

        $anio = $req->integer('anio');
        $division = $anio ? (strtoupper(trim((string) $req->query('division', ''))) ?: null) : null;

        if ($anio) {
            $this->assertCanVerCurso($req, $anio, $division);
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
        $mejoresCursos = $cursosOrdenados->sortByDesc('porcentaje')->take(10)->values();
        $peoresCursos = $cursosOrdenados->sortBy('porcentaje')->take(10)->values();

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
        // Los rankings van por porcentaje de asistencia, que es lo que dice la
        // pantalla ("mayor / menor asistencia"). Antes el "peor" se ordenaba por
        // cantidad de faltas: un alumno de un curso con muchas clases podía salir
        // primero teniendo mejor porcentaje que el segundo.
        $mejoresAlumnos = $alumnosOrdenados->sortByDesc('porcentaje')->take(10)->values();
        $peoresAlumnos = $alumnosOrdenados->sortBy('porcentaje')->take(10)->values();

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
        $q = Alumno::cursando();
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
        $this->assertPuedeImprimir($req);

        $claseId = (int) $req->query('clase_id', 0);
        if (!$claseId) {
            abort(422, 'Falta el parámetro: clase_id');
        }

        $clase = Clase::with('curso:id,anio,division')->findOrFail($claseId);

        $req->setUserResolver(fn() => $user);
        $this->assertCanVerCurso($req, (int) $clase->curso->anio, $clase->curso->division);

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
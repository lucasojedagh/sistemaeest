<?php

namespace App\Dominios\Calificaciones;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Calificaciones\AlumnoCalificacion;
use App\Dominios\Calificaciones\VentanaNotaFinal;
use App\Dominios\Cursos\Materia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Support\ActivityLogger;
use App\Support\SchoolYear;

// Controlador para la gestion de calificaciones de alumnos.
// Permite consultar, registrar y actualizar notas por materia, periodo y ciclo lectivo,
// asi como calcular promedios y estados de materia segun la normativa vigente.
class AlumnoCalificacionesController extends Controller
{
    use \App\Support\AuthorizesScope;
    // Obtiene las calificaciones de un alumno con filtros opcionales por ciclo lectivo, periodo y anio.
    // Devuelve un mapa legacy para compatibilidad con el frontend y un detalle agrupado con promedios.
    /**
     * GET /api/alumnos/{alumno}/calificaciones?ciclo=2025&periodo=1&anio=2|all
     *    ó /api/alumnos/{alumno}/calificaciones?ciclo_lectivo=2025&periodo=1&anio=2|all
     *
     * - Devuelve notas (filtradas por ciclo/período si se envían)
     * - Filtro opcional por año de cursada (?anio=1..7 | all | omitido)
     * - Respuesta compatible con el front (mapa legacy) + detalle rico
     */
    public function show(Request $req, Alumno $alumno)
    {
        $this->assertCanGradeAlumno($req, $alumno);

        $cicloParam = $req->query('ciclo', $req->query('ciclo_lectivo'));
        $ciclo      = $this->normalizeCiclo($cicloParam);
        $periodo    = $this->normalizePeriodo($req->query('periodo')); // null | 0..2
        $anioSel    = $this->normalizeAnioParam($req->query('anio'));  // int|null (null = all)

        $rows = AlumnoCalificacion::query()
            ->where('alumno_id', $alumno->id)
            ->leftJoin('materias', 'materias.id', '=', 'alumno_calificaciones.materia_id')
            ->selectRaw('alumno_calificaciones.*, materias.nombre as materia_nombre')
            ->when($ciclo, fn($q)   => $q->where('ciclo_lectivo', $ciclo))
            ->when(isset($periodo), fn($q) => $q->where('periodo', $periodo))
            ->when(isset($anioSel), fn($q)  => $q->where('alumno_calificaciones.anio', $anioSel))
            ->orderBy('fecha')
            ->get();

        // Construye el mapa legacy con clave compuesta "anio|division|Materia" para compatibilidad con el frontend.
        // ====== Mapa legacy: "anio|division|Materia" => [ {...}, ... ]
        $legacy = [];
        foreach ($rows as $r) {
            $anioTxt = $r->anio ? \App\Support\CourseLabel::anio($r->anio) : '';
            $div     = $r->division ? strtoupper($r->division) : '';
            $matNom  = $r->materia_nombre ?: ''; // en esquema actual siempre vamos por FK
            if ($matNom === '') continue;        // evitamos claves vacías si hubiera datos incompletos

            $key = trim($anioTxt . '|' . $div . '|' . $matNom, '|');

            $legacy[$key] ??= [];
            $legacy[$key][] = [
                'valor'         => $this->valorLeido($r->valor),
                'fecha'         => $this->asDateYmd($r->fecha),
                'periodo'       => is_numeric($r->periodo) ? (int)$r->periodo : null,
                'ciclo_lectivo' => $r->ciclo_lectivo ? (int)$r->ciclo_lectivo : null,
            ];
        }

        // Agrupa las calificaciones por materia, anio, division y ciclo lectivo para generar el detalle estructurado.
        // ====== Detalle por materia/año/div/ciclo con promedios por período
        $agrupados = [];
        foreach ($rows as $r) {
            $matId   = (int) ($r->materia_id ?? 0);
            $matName = $r->materia_nombre ?: null;
            if ($matId === 0 && !$matName) {
                // si llegara a existir un registro huérfano, lo salteamos
                continue;
            }

            $k = implode('|', [
                $matId ?: $matName, // evita colisiones si matId es 0 (no debería pasar)
                (int)$r->anio,
                strtoupper((string)$r->division),
                (int)$r->ciclo_lectivo
            ]);

            if (!isset($agrupados[$k])) {
                $agrupados[$k] = [
                    'materia_id'     => $matId ?: null,
                    'materia_nombre' => $matName,
                    'anio'           => (int)$r->anio,
                    'division'       => $r->division ? strtoupper($r->division) : null,
                    'ciclo_lectivo'  => $r->ciclo_lectivo ? (int)$r->ciclo_lectivo : null,
                    'notas'          => [],
                    'promedios'      => ['0'=>null,'1'=>null,'2'=>null],
                ];
            }

            $agrupados[$k]['notas'][] = [
                'valor'   => $this->valorLeido($r->valor),
                'fecha'   => $this->asDateYmd($r->fecha),
                'periodo' => is_numeric($r->periodo) ? (int)$r->periodo : null,
                'tipo'    => $r->tipo_calificacion ?? 'regular',
            ];
        }

        // Calcula los promedios por periodo (0, 1, 2) y el promedio general.
        // Solo promedian las notas regulares: intensificaciones, preliminares
        // y finales se muestran pero no entran en el promedio (misma regla
        // que el resumen, la planilla y los promedios persistidos).
        // Calcular promedios por período + general
        $allValores = [];
        foreach ($agrupados as &$item) {
            $byP = [0=>[],1=>[],2=>[]];

            foreach ($item['notas'] as $n) {
                if (($n['tipo'] ?? 'regular') !== 'regular') continue;
                $p = is_numeric($n['periodo']) ? (int)$n['periodo'] : 0;
                $p = in_array($p, [0,1,2], true) ? $p : 0;
                // Solo numeros: un TEA no es un 0 y no entra al promedio.
                $f = $this->notaNumerica($n['valor']);
                if ($f === null) continue;
                $byP[$p][]   = $f;
                $allValores[] = $f;
            }

            foreach ($byP as $p => $vals) {
                $item['promedios'][(string)$p] =
                    count($vals) ? round(array_sum($vals) / count($vals), 2) : null;
            }
        }
        unset($item);

        $promedioGeneral = $this->promedioDe($allValores);

        $aniosPresentes = collect($rows)->pluck('anio')->filter()->unique()->sort()->values()->all();

        return response()->json([
            'alumno' => [
                'id'        => $alumno->id,
                'nombre'    => $alumno->nombre,
                'apellido'  => $alumno->apellido,
                'anio'      => $alumno->anio,   // ej "3° E"
            ],
            'notas_por_materia' => $legacy,                  // compat con el front
            'detalle'           => array_values($agrupados), // nuevo formato rico
            'promedio_general'  => $promedioGeneral,
            'anios_en_resultado'=> $aniosPresentes,
            'filtros'           => [
                'ciclo_lectivo' => $ciclo,
                'periodo'       => $periodo, // null | 0..2
                'anio'          => $anioSel, // null = all
            ],
        ]);
    }

    // Actualiza las calificaciones de un alumno aceptando formato legacy (notas_por_materia) o nuevo (entradas).
    // Recalcula los promedios y los estados de materia tras la actualizacion.
    /**
     * PUT /api/alumnos/{alumno}/calificaciones
     *
     * Acepta legacy (notas_por_materia) y nuevo (entradas).
     * Recalcula y persiste los promedios en alumno_promedios.
     */
    public function update(Request $req, Alumno $alumno)
    {
        $this->assertCanGradeAlumno($req, $alumno);

        $hasLegacy = $req->has('notas_por_materia');
        $hasNew    = $req->has('entradas');

        if (!$hasLegacy && !$hasNew) {
            return response()->json(['message' => 'Payload inválido.'], 422);
        }

        return DB::transaction(function () use ($req, $alumno, $hasLegacy, $hasNew) {

            $touched = []; // cada item: ['anio'=>int,'division'=>?string,'ciclo'=>int]

            // Procesa el formato legacy: las notas se envian agrupadas por clave "anio|division|materia" en periodo 0.
            // ----- Ruta legacy (periodo 0) -----
            if ($hasLegacy) {
                $data = $req->validate([
                    'notas_por_materia' => 'required|array',
                ]);
                $map = $data['notas_por_materia'];

                foreach ($map as $key => $items) {
                    // "anio|division|materia"
                    [$anioTxt, $division, $materiaName] = array_pad(explode('|', (string)$key, 3), 3, null);
                    $anio       = $this->parseAnio($anioTxt);
                    $division   = $division ? Str::upper(trim($division)) : null;
                    $materiaNom = trim((string)$materiaName);
                    if ($materiaNom === '' || !$anio) continue;

                    // Buscar/crear materia
                    $materia = Materia::query()
                        ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($materiaNom, 'UTF-8')])
                        ->where('anio', $anio)
                        ->first();

                    if (!$materia) {
                        $materia = Materia::create([
                            'nombre' => $materiaNom,
                            'anio'   => $anio,
                            'activo' => true,
                        ]);
                        Cache::forget('materias.todas');
                    }

                    // Reemplazo: (alumno, materia, anio, division, periodo=0)
                    AlumnoCalificacion::where('alumno_id', $alumno->id)
                        ->where('materia_id', $materia->id)
                        ->where('anio', $anio)
                        ->where('division', $division)
                        ->where('periodo', 0)
                        ->delete();

                    $insert = [];
                    foreach ((array)$items as $it) {
                        $valor = $this->normalizeNota($it['valor'] ?? $it['nota'] ?? null);
                        if ($valor === null) continue;

                        $fecha = $this->normalizeFecha($it['fecha'] ?? null);
                        $ciclo = (int)date('Y', strtotime($fecha));

                        $insert[] = [
                            'alumno_id'     => $alumno->id,
                            'materia_id'    => $materia->id,
                            'anio'          => $anio,
                            'division'      => $division,
                            'valor'         => $valor,
                            'fecha'         => $fecha,
                            'ciclo_lectivo' => $ciclo,
                            'periodo'       => 0,
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ];

                        $touched[] = ['anio' => $anio, 'division' => $division, 'ciclo' => $ciclo];
                    }
                    if ($insert) AlumnoCalificacion::insert($insert);
                }
            }

            // Procesa el nuevo formato con periodos 1 y 2, tipo de calificacion (regular/intensificacion) y materia por ID o nombre.
            // ----- Nuevo payload (periodo 1/2 y separación por ciclo con tipo_calificacion) -----
            if ($hasNew) {
                $data = $req->validate([
                    'entradas'                      => ['required','array','min:1'],
                    'entradas.*.anio'               => ['required','integer','min:1','max:'.\App\Support\SchoolYear::maxYear()],
                    'entradas.*.division'           => ['nullable','string','max:2'],
                    'entradas.*.ciclo_lectivo'      => ['required','integer','min:2000','max:2100'],
                    'entradas.*.periodo'            => ['required','integer','min:0','max:2'],
                    'entradas.*.tipo_calificacion'  => ['nullable','string','max:40'],
                    'entradas.*.materia_id'         => ['nullable','integer','min:1'],
                    'entradas.*.materia_nombre'     => ['nullable','string','max:120'],
                    'entradas.*.notas'              => ['required','array','min:1'],
                    'entradas.*.notas.*.valor'      => ['nullable'],
                    'entradas.*.notas.*.fecha'      => ['nullable','date'],
                    'entradas.*.notas.*.descripcion' => ['nullable','string','max:500'],
                    'entradas.*.detalle'             => ['nullable','string','max:1000'],
                ]);

                // La nota final solo se puede cargar cuando la ventana lo permite (para todos).
                // final_cuatrimestre respeta el alcance por período; final_anio (promedio
                // automático) se permite si la ventana está abierta en general.
                $ventanaCheck = \App\Dominios\Calificaciones\VentanaNotaFinal::actual();
                $ventanaAbiertaCheck = $ventanaCheck ? $ventanaCheck->estaAbierta() : false;
                foreach ($data['entradas'] as $eCheck) {
                    $tipoCheck = $eCheck['tipo_calificacion'] ?? 'regular';
                    if ($tipoCheck === 'final_cuatrimestre') {
                        $perCheck = (int) ($eCheck['periodo'] ?? 0);
                        $permite = $ventanaCheck ? $ventanaCheck->permitePeriodo($perCheck) : false;
                        if (!$permite) {
                            return response()->json([
                                'ok' => false,
                                'message' => 'La carga de la nota final del ' . ($perCheck === 1 ? '1er' : ($perCheck === 2 ? '2do' : '')) . ' cuatrimestre no está habilitada por la ventana actual.',
                            ], 403);
                        }
                    } elseif ($tipoCheck === 'final_anio' && !$ventanaAbiertaCheck) {
                        return response()->json([
                            'ok' => false,
                            'message' => 'La carga de la nota final está deshabilitada. Solo se habilita durante el período configurado por administración.',
                        ], 403);
                    }
                }

                $this->applyEntradas($alumno, $data['entradas'], $touched);
            }

            // Si hubo modificaciones, recalcula los promedios y actualiza los estados de materia para los combos afectados.
            if (!empty($touched)) {
                $this->recalcAndUpsertPromedios($alumno->id, $touched);
                $this->actualizarEstadoMaterias($alumno->id, $touched);
            }

            $tipos = array_unique(array_column($touched, 'tipo'));
            // Se manda la foto completa de las notas: el diff centralizado deja
            // solo los campos que cambiaron de verdad.
            ActivityLogger::log(
                $req,
                'Guardó calificaciones de ' . $alumno->apellido . ', ' . $alumno->nombre . ' (' . implode(', ', $tipos) . ')',
                'calificaciones.update',
                'Alumno',
                $alumno->id,
                $oldSummary ?? [],
                $newSummary ?? []
            );

            return $this->show($req, $alumno);
        });
    }

    // Procesa las entradas de calificaciones de un alumno (formato nuevo con periodo 1/2).
    // Resuelve/crea la materia, reemplaza las notas del combo y acumula los combos tocados.
    private function applyEntradas(Alumno $alumno, array $entradas, array &$touched): void
    {
        foreach ($entradas as $e) {
            $anio     = (int)$e['anio'];
            $division = isset($e['division']) ? Str::upper(trim($e['division'])) : null;
            $ciclo    = (int)$e['ciclo_lectivo'];
            $periodo  = (int)$e['periodo'];
            $tipo     = $e['tipo_calificacion'] ?? 'regular';

            // Materia
            $materia = null;
            if (!empty($e['materia_id'])) {
                $materia = Materia::find($e['materia_id']);
            }
            if (!$materia && !empty($e['materia_nombre'])) {
                $materia = Materia::query()
                    ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($e['materia_nombre'], 'UTF-8')])
                    ->when($anio, fn($q) => $q->where('anio', $anio))
                    ->first();

                if (!$materia) {
                    $materia = Materia::create([
                        'nombre' => $e['materia_nombre'],
                        'anio'   => $anio,
                        'activo' => true,
                    ]);
                    Cache::forget('materias.todas');
                }
            }
            if (!$materia) continue;

            // Reemplazo por combo (alumno, materia, anio, division, ciclo, periodo, tipo)
            $deleteQuery = AlumnoCalificacion::where('alumno_id', $alumno->id)
                ->where('materia_id', $materia->id)
                ->where('anio', $anio)
                ->where('division', $division)
                ->where('ciclo_lectivo', $ciclo)
                ->where('periodo', $periodo);

            if ($tipo !== 'regular') {
                $deleteQuery->where('tipo_calificacion', $tipo);
            } else {
                $deleteQuery->whereIn('tipo_calificacion', ['regular', '']);
            }

            $deleteQuery->delete();

            $insert = [];
            $detalle = null;
            if (!empty($e['detalle'])) {
                $raw = is_string($e['detalle']) ? $e['detalle'] : json_encode($e['detalle']);
                $dec = json_decode($raw, true);
                // Acepta JSON estructurado o texto plano (motivo del formulario).
                $detalle = (json_last_error() === JSON_ERROR_NONE) ? $dec : $raw;
            }
            foreach ($e['notas'] as $n) {
                $valor = $this->normalizeNota($n['valor'] ?? null);
                if ($valor === null) continue;
                $fecha = $this->normalizeFecha($n['fecha'] ?? null);
                $descripcion = $n['descripcion'] ?? null;

                $insert[] = [
                    'alumno_id'        => $alumno->id,
                    'materia_id'       => $materia->id,
                    'anio'             => $anio,
                    'division'         => $division,
                    'valor'            => $valor,
                    'fecha'            => $fecha,
                    'descripcion'      => $descripcion,
                    'ciclo_lectivo'    => $ciclo,
                    'periodo'          => $periodo,
                    'tipo_calificacion' => $tipo,
                    'detalle'          => $detalle ? json_encode($detalle) : null,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
            }
            if ($insert) AlumnoCalificacion::insert($insert);

            $touched[] = ['anio' => $anio, 'division' => $division, 'ciclo' => $ciclo, 'tipo' => $tipo, 'materia_id' => $materia->id];
        }
    }

    // PUT /api/calificaciones/batch
    // Guarda calificaciones de VARIOS alumnos en una unica peticion y registra
    // UNA sola entrada en el historial con cada alumno en el detalle.
    public function updateBatch(Request $req)
    {
        $data = $req->validate([
            'alumnos'                                  => ['required','array','min:1'],
            'alumnos.*.alumno_id'                      => ['required','integer','min:1'],
            'alumnos.*.entradas'                       => ['required','array','min:1'],
            'alumnos.*.entradas.*.anio'                => ['required','integer','min:1','max:'.\App\Support\SchoolYear::maxYear()],
            'alumnos.*.entradas.*.division'            => ['nullable','string','max:2'],
            'alumnos.*.entradas.*.ciclo_lectivo'       => ['required','integer','min:2000','max:2100'],
            'alumnos.*.entradas.*.periodo'             => ['required','integer','min:0','max:2'],
            'alumnos.*.entradas.*.tipo_calificacion'   => ['nullable','string','max:40'],
            'alumnos.*.entradas.*.materia_id'          => ['nullable','integer','min:1'],
            'alumnos.*.entradas.*.materia_nombre'      => ['nullable','string','max:120'],
            'alumnos.*.entradas.*.notas'               => ['required','array','min:1'],
            'alumnos.*.entradas.*.notas.*.valor'       => ['nullable'],
            'alumnos.*.entradas.*.notas.*.fecha'       => ['nullable','date'],
            'alumnos.*.entradas.*.notas.*.descripcion' => ['nullable','string','max:500'],
            'alumnos.*.entradas.*.detalle'             => ['nullable','string','max:1000'],
        ]);

        $items = [];
        $user = $req->user();
        $puedeFinalSiempre = in_array($user->role, ['admin', 'jefe_preceptores'], true);
        $ventana = VentanaNotaFinal::actual();
        $ventanaAbierta = $ventana ? $ventana->estaAbierta() : false;

        // Una sola lectura de alumnos (evita N+1): se mantiene el mismo orden
        // y el mismo 404 del primer faltante que antes.
        $ids = collect($data['alumnos'])->pluck('alumno_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $alumnosById = Alumno::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($data['alumnos'] as $a) {
            $alumno = $alumnosById[(int) $a['alumno_id']] ?? null;
            if (!$alumno) {
                return response()->json(['ok' => false, 'message' => 'Alumno no encontrado: ' . $a['alumno_id']], 404);
            }
            $this->assertCanGradeAlumno($req, $alumno); // 403 si no puede cargar notas ahi

            // La nota final respeta el alcance de la ventana (para todos).
            // final_cuatrimestre: solo el período habilitado; final_anio: ventana abierta.
            foreach ($a['entradas'] as $entrada) {
                $tipoEntrada = $entrada['tipo_calificacion'] ?? 'regular';
                if ($tipoEntrada === 'final_cuatrimestre') {
                    $perEntrada = (int) ($entrada['periodo'] ?? 0);
                    $permite = $ventana ? $ventana->permitePeriodo($perEntrada) : false;
                    if (!$permite) {
                        return response()->json([
                            'ok' => false,
                            'message' => 'La carga de la nota final del ' . ($perEntrada === 1 ? '1er' : ($perEntrada === 2 ? '2do' : '')) . ' cuatrimestre no está habilitada por la ventana actual.',
                        ], 403);
                    }
                } elseif ($tipoEntrada === 'final_anio') {
                    if (!$ventanaAbierta) {
                        return response()->json([
                            'ok' => false,
                            'message' => 'La carga de la nota final está deshabilitada. Solo se habilita durante el período configurado por administración.',
                        ], 403);
                    }
                }
            }

            $items[] = ['alumno' => $alumno, 'entradas' => $a['entradas']];
        }

        $fallidos = [];
        $gradesByStudent = [];

        // Una sola lectura de calificaciones previas para todos (evita N+1).
        // Se refresca por alumno tras guardar, así un alumno repetido en el
        // lote ve los cambios de su pasada anterior (igual que antes).
        $previasPorAlumno = AlumnoCalificacion::whereIn('alumno_id', $ids)->get()->groupBy('alumno_id');

        foreach ($items as $item) {
            $alumno = $item['alumno'];
            try {
                DB::transaction(function () use ($alumno, $item, &$gradesByStudent, &$previasPorAlumno) {
                    $oldSummary = $this->buildGradesSummary(
                        $previasPorAlumno[$alumno->id] ?? collect()
                    );

                    $touched = [];
                    $this->applyEntradas($alumno, $item['entradas'], $touched);

                    if (!empty($touched)) {
                        $this->recalcAndUpsertPromedios($alumno->id, $touched);
                        $this->actualizarEstadoMaterias($alumno->id, $touched);

                        $nuevas = AlumnoCalificacion::where('alumno_id', $alumno->id)->get();
                        $previasPorAlumno[$alumno->id] = $nuevas;
                        $newSummary = $this->buildGradesSummary($nuevas);
                        if ($oldSummary != $newSummary) {
                            $gradesByStudent[$alumno->apellido . ', ' . $alumno->nombre] = [
                                'old' => $oldSummary,
                                'new' => $newSummary,
                            ];
                        }
                    }
                });
            } catch (\Throwable $ex) {
                report($ex);
                $fallidos[] = [
                    'alumno_id' => $alumno->id,
                    'nombre'    => $alumno->apellido . ', ' . $alumno->nombre,
                ];
            }
        }

        // Historial: una sola tarjeta con cada alumno en el detalle.
        if (!empty($gradesByStudent)) {
            $oldValues = [];
            $newValues = [];
            foreach ($gradesByStudent as $nombre => $g) {
                $oldValues[$nombre] = $g['old'];
                $newValues[$nombre] = $g['new'];
            }
            $n = count($gradesByStudent);
            $action = 'Modificó calificaciones de ' . $n . ' ' . ($n === 1 ? 'alumno' : 'alumnos');
            ActivityLogger::log($req, $action, 'calificaciones.update', 'Alumno', null, $oldValues ?: null, $newValues ?: null);
        }

        return response()->json([
            'ok'        => count($fallidos) === 0,
            'guardados' => count($items) - count($fallidos),
            'fallidos'  => $fallidos,
        ]);
    }

    // Obtiene las calificaciones de todos los alumnos de un curso para una materia y ciclo lectivo especificos.
    // Incluye promedios por periodo, notas de intensificacion y estado de cada materia.
    /**
     * GET /api/calificaciones/por-curso-materia?anio=&division=&materia_id=&ciclo=
     * Devuelve todos los alumnos de ese curso con sus notas para la materia+periodo indicados.
     */
    public function porCursoMateria(Request $req)
    {
        $anio      = $req->integer('anio');
        $division  = strtoupper(trim((string) $req->query('division', '')));
        $materiaId = $req->integer('materia_id');
        $ciclo     = $req->integer('ciclo', date('Y'));

        if (!$anio || !$division || !$materiaId) {
            return response()->json(['message' => 'Faltan parámetros: anio, division, materia_id'], 422);
        }

        // Verificar permiso para ver calificaciones de este curso
        if (!$this->userCanWriteAll($req) && !$this->userIsAssignedTo($req, $anio, $division)) {
            abort(403, 'No tenés permiso para ver calificaciones de este curso.');
        }

        // Un profesor solo ve calificaciones de las materias que ensena en ese curso
        // (o que cubre como suplente de otro profesor). Si no tiene ni
        // asignaciones propias ni coberturas, queda el filtro por curso.
        if (!$this->userCanWriteAll($req) && $req->user()->role === 'profesor') {
            $profesor = \App\Dominios\Profesores\Profesor::where('email', $req->user()->email)->first();
            $conAsignaciones = $profesor && \App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)->exists();
            $conCoberturas = $profesor && \App\Dominios\Profesores\ProfesorSuplencia::where('suplente_id', $profesor->id)->exists();
            if ($conAsignaciones || $conCoberturas) {
                $ensena = $profesor && \App\Dominios\Profesores\ProfesorAsignacion::where('profesor_id', $profesor->id)
                    ->where('materia_id', $materiaId)
                    ->where('anio', $anio)
                    ->where('division', $division)
                    ->exists();
                if (!$ensena && !($profesor && \App\Support\Suplencias::cubreMateria($profesor->id, $materiaId, $anio, $division))) {
                    abort(403, 'No tenés permiso para ver calificaciones de esta materia.');
                }
            }
        }

        $materia = Materia::select(['id','nombre','anio'])->find($materiaId);
        if (!$materia) {
            return response()->json(['message' => 'Materia no encontrada'], 404);
        }

        $alumnos = Alumno::where('anio', $anio)
            ->where('division', $division)
            ->orderBy('apellido')->orderBy('nombre')
            ->get(['id','nombre','apellido','genero','dni','grupo']);

        $alumnoIds = $alumnos->pluck('id');

        $calificaciones = AlumnoCalificacion::whereIn('alumno_id', $alumnoIds)
            ->where('materia_id', $materiaId)
            ->where('ciclo_lectivo', $ciclo)
            ->get(['alumno_id','valor','fecha','descripcion','detalle','periodo','tipo_calificacion']);

        $estados = \App\Dominios\Calificaciones\AlumnoMateriaEstado::whereIn('alumno_id', $alumnoIds)
            ->where('materia_id', $materiaId)
            ->where('ciclo_lectivo', $ciclo)
            ->get()
            ->keyBy('alumno_id');

            // Indexa las calificaciones por alumno para facilitar el armado de la respuesta.
            $calMap = [];
        foreach ($calificaciones as $c) {
            $aid = $c->alumno_id;
            $tipo = $c->tipo_calificacion ?: 'regular';
            if (!isset($calMap[$aid])) $calMap[$aid] = [];
            $calMap[$aid][] = [
                'valor'            => $this->valorLeido($c->valor),
                'descripcion'      => $c->descripcion,
                'detalle'          => $c->detalle,
                'fecha'            => $c->fecha,
                'periodo'          => (int) $c->periodo,
                'tipo_calificacion' => $tipo,
            ];
        }

        $result = [];
        foreach ($alumnos as $a) {
            $notas = $calMap[$a->id] ?? [];

            // Separa las notas por tipo (regular / intensificacion) y periodo para calcular promedios individuales.
            // Separar por tipo
            $regularP1 = []; $regularP2 = [];
            $regularP1Raw = []; $regularP2Raw = [];
            $prelimP1Raw = []; $prelimP2Raw = [];
            $finalAnioRaw = [];
            $finalCuatriP1Raw = []; $finalCuatriP2Raw = [];
            $intensificacion = [];
            foreach ($notas as $n) {
                $t = $n['tipo_calificacion'];
                $p = $n['periodo'];
                $entry = ['valor' => $n['valor'], 'descripcion' => $n['descripcion'] ?? null];
                if ($t === 'regular' && $p === 1) { $regularP1[] = $n['valor']; $regularP1Raw[] = $entry; }
                elseif ($t === 'regular' && $p === 2) { $regularP2[] = $n['valor']; $regularP2Raw[] = $entry; }
                elseif ($t === 'preliminar' && $p === 1) { $prelimP1Raw[] = $entry; }
                elseif ($t === 'preliminar' && $p === 2) { $prelimP2Raw[] = $entry; }
                elseif ($t === 'final_anio') { $finalAnioRaw[] = $entry; }
                elseif ($t === 'final_cuatrimestre' && $p === 1) { $finalCuatriP1Raw[] = $entry; }
                elseif ($t === 'final_cuatrimestre' && $p === 2) { $finalCuatriP2Raw[] = $entry; }
                else $intensificacion[] = $n;
            }

            $est = $estados->get($a->id);

            // Extraer detalle (nota_texto, calificacion_final override) del primer registro de cada periodo
            $p1Detalle = null; $p2Detalle = null;
            foreach ($notas as $n) {
                if ($n['tipo_calificacion'] === 'regular' && $n['periodo'] === 1 && !$p1Detalle && $n['detalle']) {
                    $p1Detalle = is_string($n['detalle']) ? json_decode($n['detalle'], true) : $n['detalle'];
                }
                if ($n['tipo_calificacion'] === 'regular' && $n['periodo'] === 2 && !$p2Detalle && $n['detalle']) {
                    $p2Detalle = is_string($n['detalle']) ? json_decode($n['detalle'], true) : $n['detalle'];
                }
            }

            // Organizar calificaciones por periodo con descripcion.
            // La preliminar se muestra venga de donde venga: hay dos
            // convenciones (filas tipo=preliminar del formulario, y
            // detalle.nota_texto de la planilla) y cada pantalla solo leia
            // la suya, asi que al reabrir parecia que no se habia guardado.
            $califs = [
                'p1' => array_values(array_filter($regularP1Raw ?? [], fn($n) => true)),
                'p2' => array_values(array_filter($regularP2Raw ?? [], fn($n) => true)),
                'p1_preliminar' => $this->preliminarVisible($prelimP1Raw ?? [], $p1Detalle['nota_texto'] ?? null),
                'p2_preliminar' => $this->preliminarVisible($prelimP2Raw ?? [], $p2Detalle['nota_texto'] ?? null),
                'p1_final_cuatri' => array_values(array_filter($finalCuatriP1Raw ?? [], fn($n) => true)),
                'p2_final_cuatri' => array_values(array_filter($finalCuatriP2Raw ?? [], fn($n) => true)),
                // Alias para compatibilidad con el frontend.
                'p1_final_cuatrimestre' => array_values(array_filter($finalCuatriP1Raw ?? [], fn($n) => true)),
                'p2_final_cuatrimestre' => array_values(array_filter($finalCuatriP2Raw ?? [], fn($n) => true)),
                'final_anio'  => array_values(array_filter($finalAnioRaw ?? [], fn($n) => true)),
                'p1_nota_texto' => $p1Detalle['nota_texto'] ?? null,
                'p2_nota_texto' => $p2Detalle['nota_texto'] ?? null,
                'p1_final'      => $p1Detalle['calificacion_final'] ?? null,
                'p2_final'      => $p2Detalle['calificacion_final'] ?? null,
            ];

            $result[] = [
                'alumno_id'       => $a->id,
                'nombre'          => $a->nombre,
                'apellido'        => $a->apellido,
                'genero'          => $a->genero,
                'dni'             => $a->dni,
                'grupo'           => $a->grupo,
                'calificaciones'  => $califs,
                'promedio_p1'     => $this->promedioDe($regularP1),
                'promedio_p2'     => $this->promedioDe($regularP2),
                'p1_notas'        => $regularP1,
                'p2_notas'        => $regularP2,
                'intensificaciones' => $intensificacion,
                'estado'          => $est ? [
                    'p1_estado'                        => $est->p1_estado,
                    'p2_estado'                        => $est->p2_estado,
                    'estado_final'                     => $est->estado_final,
                    'nota_final'                       => $est->nota_final !== null ? (float) $est->nota_final : null,
                    'nota_intensificacion_1cuatri'     => $est->nota_intensificacion_1cuatri !== null ? (float) $est->nota_intensificacion_1cuatri : null,
                    'nota_intensificacion_diciembre'   => $est->nota_intensificacion_diciembre !== null ? (float) $est->nota_intensificacion_diciembre : null,
                    'nota_intensificacion_febrero'     => $est->nota_intensificacion_febrero !== null ? (float) $est->nota_intensificacion_febrero : null,
                    'nota_intensificacion_marzo_abril' => $est->nota_intensificacion_marzo_abril !== null ? (float) $est->nota_intensificacion_marzo_abril : null,
                ] : null,
            ];
        }

        $asignacion = \App\Dominios\Profesores\ProfesorAsignacion::where('materia_id', $materiaId)
            ->where('anio', $anio)
            ->where('division', $division)
            ->with('profesor')
            ->first();
        $profesor = $asignacion && $asignacion->profesor
            ? $asignacion->profesor->apellido . ', ' . $asignacion->profesor->nombre
            : null;

        return response()->json([
            'materia'  => ['id' => $materia->id, 'nombre' => $materia->nombre, 'anio' => $materia->anio],
            'curso'    => ['anio' => $anio, 'division' => $division],
            'alumnos'  => $result,
            'profesor' => $profesor,
            'ciclo'    => $ciclo,
        ]);
    }

    // Devuelve la lista de anios en los que el alumno tiene calificaciones registradas.
    // Detecta automaticamente si la columna 'anio' esta en alumno_calificaciones o debe obtenerla desde materias.
    /**
     * GET /api/alumnos/{alumno}/anios-con-notas
     * Devuelve { anios: [2,3,...] }
     */
    public function aniosConNotas(Request $req, Alumno $alumno)
    {
        if (!$this->userCanWriteAll($req) && !$this->userIsAssignedTo($req, $alumno->anio, $alumno->division)) {
            abort(403, 'No tenés permiso para ver esta información.');
        }

        $q = DB::table('alumno_calificaciones as ac')
            ->where('ac.alumno_id', $alumno->id);

        if (!Schema::hasColumn('alumno_calificaciones', 'anio')) {
            $q->leftJoin('materias as m', 'm.id', '=', 'ac.materia_id')
              ->selectRaw('DISTINCT m.anio as anio');
        } else {
            $q->selectRaw('DISTINCT ac.anio as anio');
        }

        $anios = $q->pluck('anio')
            ->filter(fn($n) => is_numeric($n) && (int)$n > 0)
            ->map(fn($n) => (int)$n)
            ->sort()
            ->values();

        return response()->json(['anios' => $anios]);
    }

    /* ========================= Helpers ========================= */

    private function parseAnio(?string $txt): ?int
    {
        if (!$txt) return null;
        $n = (int) preg_replace('/\D+/', '', $txt);
        return $n > 0 ? $n : null;
    }

    private function normalizeAnioParam($v): ?int
    {
        if ($v === null || $v === '' || strtolower((string)$v) === 'all') {
            return null; // sin filtro por año
        }
        if (is_numeric($v)) {
            $n = (int)$v;
            return $n > 0 ? $n : null;
        }
        $n = (int) preg_replace('/\D+/', '', (string)$v);
        return $n > 0 ? $n : null;
    }

    // Actualiza los estados de materia para los combos de anio/division/ciclo/materia que fueron modificados.
    // Deduplica los combos y delega el calculo individual a recalcularEstado.
    /**
     * Actualiza alumno_materias_estado para los combos tocados.
     * $combos = [['anio'=>int, 'division'=>?string, 'ciclo'=>int, 'tipo'=>string, 'materia_id'=>int], ...]
     */
    private function actualizarEstadoMaterias(int $alumnoId, array $combos): void
    {
        $uniq = [];
        foreach ($combos as $c) {
            $k = $c['materia_id'] . '|' . ($c['ciclo'] ?? 0) . '|' . ($c['anio'] ?? 0) . '|' . ($c['division'] ?? '');
            $uniq[$k] = [
                'materia_id' => (int)$c['materia_id'],
                'ciclo'      => (int)($c['ciclo'] ?? 0),
                'anio'       => (int)($c['anio'] ?? 0),
                'division'   => $c['division'] ?? null,
            ];
        }

        foreach ($uniq as $c) {
            $this->recalcularEstado($alumnoId, $c['materia_id'], $c['anio'], $c['division'], $c['ciclo']);
        }
    }

    // Calcula y persiste el estado de una materia para un alumno en un ciclo lectivo segun la normativa vigente.
    // Evalua promedios de P1 y P2, notas de intensificacion, y determina el estado final (aprobada, pendiente, etc.).
    /**
     * Calcula y persiste el estado de una materia para un alumno en un ciclo.
     * Reglas Resolución 1650/24 + Disposición 40/26:
     * - P1 y P2: escala 1-10, aprobado ≥ 7
     * - Intensificación: escala 1-10, aprobado ≥ 4
     * - Si P1 < 7 → debe intensificar
     * - Si P2 < 7 → debe intensificar
     * - Nota final = promedio(P1,P2) si ambos ≥ 7
     * - Nota final = nota_intensificación si aprobó por intensificación
     */
    private function recalcularEstado(int $alumnoId, int $materiaId, int $anio, ?string $division, int $ciclo): void
    {
        $notas = AlumnoCalificacion::where('alumno_id', $alumnoId)
            ->where('materia_id', $materiaId)
            ->where('anio', $anio)
            ->where('division', $division)
            ->where('ciclo_lectivo', $ciclo)
            ->whereIn('periodo', [0, 1, 2])
            ->get(['valor','periodo','tipo_calificacion']);

        // Clasifica las notas en regulares de P1 y P2, e intensificaciones para su evaluacion por separado.
        $regP1 = []; $regP2 = [];
        $intensificaciones = [];
        $finalAnio = null;
        $finalCuatriP1 = null; $finalCuatriP2 = null;

        foreach ($notas as $n) {
            $tipo = $n->tipo_calificacion ?: 'regular';
            // Un TEA no es un numero: no entra al promedio ni puede marcar
            // "desaprobado" a nadie. Se saltea (antes el (float) lo volvia 0).
            $v = $this->notaNumerica($n->valor);
            if ($tipo === 'regular' && $n->periodo === 1) { if ($v !== null) $regP1[] = $v; }
            elseif ($tipo === 'regular' && $n->periodo === 2) { if ($v !== null) $regP2[] = $v; }
            elseif ($tipo === 'preliminar') continue;
            elseif ($tipo === 'final_anio') { if ($v !== null) $finalAnio = $finalAnio ?? $v; continue; }
            elseif ($tipo === 'final_cuatrimestre' && (int) $n->periodo === 1) { if ($v !== null) $finalCuatriP1 = $finalCuatriP1 ?? $v; continue; }
            elseif ($tipo === 'final_cuatrimestre' && (int) $n->periodo === 2) { if ($v !== null) $finalCuatriP2 = $finalCuatriP2 ?? $v; continue; }
            // Compat: finales de cuatrimestre guardados con periodo 0 o tipos legacy.
            elseif (in_array($tipo, ['final_p1', 'final_cuatrimestre_p1'], true)) { if ($v !== null) $finalCuatriP1 = $finalCuatriP1 ?? $v; continue; }
            elseif (in_array($tipo, ['final_p2', 'final_cuatrimestre_p2'], true)) { if ($v !== null) $finalCuatriP2 = $finalCuatriP2 ?? $v; continue; }
            else $intensificaciones[] = ['valor' => $v, 'tipo' => $tipo];
        }

        $promP1 = $this->promedioDe($regP1);
        $promP2 = $this->promedioDe($regP2);

        $p1Aprobado = $promP1 !== null && $promP1 >= 7;
        $p2Aprobado = $promP2 !== null && $promP2 >= 7;

        $p1Estado = $promP1 === null ? 'pendiente' : ($p1Aprobado ? 'aprobado' : 'desaprobado');
        $p2Estado = $promP2 === null ? 'pendiente' : ($p2Aprobado ? 'aprobado' : 'desaprobado');

        // Identifica las notas de intensificacion por su tipo (febrero, julio, diciembre_pre, diciembre_post).
        // Buscar nota de intensificación más reciente
        $int1Cuatri = null; $intDic = null; $intFeb = null; $intMarAbr = null;
        $mejorInt = null;
        $aprobadoInt = false;

        foreach ($intensificaciones as $int) {
            $v = $int['valor'];
            $t = $int['tipo'];
            if ($t === 'intensificacion_1cuatri') $int1Cuatri = $v;
            elseif ($t === 'intensificacion_diciembre') $intDic = $v;
            elseif ($t === 'intensificacion_febrero') $intFeb = $v;
            elseif ($t === 'intensificacion_marzo_abril') $intMarAbr = $v;

            if ($v >= 4) {
                $aprobadoInt = true;
                $mejorInt = max($mejorInt ?? 0, $v);
            }
        }

        // Determina el estado final de la materia segun los promedios de P1 y P2 y las notas de intensificacion.
        // Estado final
        $notaFinal = null;
        $estadoFinal = null;

        if ($p1Aprobado && $p2Aprobado && $promP1 !== null && $promP2 !== null) {
            // Si ambos periodos estan aprobados, la nota final es el promedio de P1 y P2.
            // Ambos aprobados: nota final = promedio
            $notaFinal = round(($promP1 + $promP2) / 2, 2);
            $estadoFinal = 'aprobada';
        } elseif ($aprobadoInt) {
            // Si aprobo por intensificacion, la nota final es la mejor nota de intensificacion.
            $notaFinal = $mejorInt;
            $estadoFinal = 'aprobada';
        } elseif ($promP1 !== null || $promP2 !== null || count($intensificaciones) > 0) {
            // Tiene registros pero no alcanzo los minimos; determina la siguiente instancia de intensificacion segun el avance.
            // Tiene algún registro pero no aprobó
            if ($intMarAbr !== null || $intDic !== null) {
                $estadoFinal = ($intMarAbr !== null && $intMarAbr < 4) ? 'csa' : 'intensifica_febrero';
            } elseif ($int1Cuatri !== null) {
                $estadoFinal = ($int1Cuatri < 4) ? 'intensifica_diciembre' : 'intensifica_febrero';
            } elseif ($intFeb !== null) {
                $estadoFinal = ($intFeb < 4) ? 'intensifica_1cuatri' : 'intensifica_diciembre';
            } else {
                $estadoFinal = (!empty($regP1) || !empty($regP2)) ? 'intensifica_diciembre' : 'pendiente';
            }
        } else {
            $estadoFinal = 'pendiente';
        }

        // La nota final del año ingresada manualmente reemplaza el cálculo automático.
        // Nuevo criterio: si hay dos notas finales de cuatrimestre, la final del año
        // es su promedio automático y tiene prioridad sobre la manual.
        if ($finalCuatriP1 !== null && $finalCuatriP2 !== null) {
            $notaFinal = round(($finalCuatriP1 + $finalCuatriP2) / 2, 2);
            $estadoFinal = $notaFinal >= 7 ? 'aprobada' : 'desaprobada';
        } elseif ($finalAnio !== null) {
            $notaFinal = $finalAnio;
            $estadoFinal = $finalAnio >= 7 ? 'aprobada' : 'desaprobada';
        }

        \App\Dominios\Calificaciones\AlumnoMateriaEstado::updateOrCreate(
            [
                'alumno_id'     => $alumnoId,
                'materia_id'    => $materiaId,
                'ciclo_lectivo' => $ciclo,
            ],
            [
                'anio'                              => $anio,
                'division'                          => $division,
                'p1_estado'                         => $p1Estado,
                'p2_estado'                         => $p2Estado,
                'nota_final'                        => $notaFinal,
                'estado_final'                      => $estadoFinal,
                'nota_intensificacion_1cuatri'     => $int1Cuatri,
                'nota_intensificacion_diciembre'   => $intDic,
                'nota_intensificacion_febrero'     => $intFeb,
                'nota_intensificacion_marzo_abril' => $intMarAbr,
                'updated_at'                        => now(),
            ]
        );
    }

    private function normalizeNota($v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = trim((string) $v);
        // Aceptar coma decimal ("8,5" -> "8.5", común en Argentina).
        $s = str_replace(',', '.', $s);
        $up = strtoupper($s);
        if (in_array($up, ['TEA', 'TEP', 'TED'], true)) return $up;
        if (!is_numeric($s)) return null;
        $f = (float) $s;
        if (!is_finite($f)) return null;
        if ($f < 1 || $f > 10) return null;
        // guardar sin .0 innecesario (8.0 -> "8")
        $str = number_format($f, 1, '.', '');
        $str = rtrim(rtrim($str, '0'), '.');
        return $str === '' ? '0' : $str;
    }

    // TEA/TEP/TED no son numeros: al leer hay que conservar el texto tal cual.
    // Un (float) los convierte en 0 y ese 0 despues arruina promedios ("4" en
    // vez de "8") y estados ("desaprobado" en vez de "pendiente").
    // Valor tal cual para mostrar: numero, TEA/TEP/TED, o null si no hay nada.
    private function valorLeido($v)
    {
        if (is_bool($v)) return null;
        $s = trim((string) $v);
        if ($s === '') return null;
        if (is_numeric($s)) {
            $f = (float) $s;
            return $f > 0 ? $f : null;
        }
        return strtoupper($s);
    }

    // Numero para promediar: solo valores numericos positivos. TEA/TEP/TED y
    // cualquier otro texto no entran al promedio (null = no se cuenta).
    private function notaNumerica($v): ?float
    {
        $s = is_bool($v) ? '' : trim((string) $v);
        $s = str_replace(',', '.', $s);
        if ($s === '' || !is_numeric($s)) return null;
        $f = (float) $s;
        return $f > 0 ? $f : null;
    }

    // Promedio de una lista que puede traer textos (TEA): solo promedia numeros.
    private function promedioDe(array $vals): ?float
    {
        $nums = [];
        foreach ($vals as $v) {
            $f = $this->notaNumerica($v);
            if ($f !== null) $nums[] = $f;
        }
        return count($nums) ? round(array_sum($nums) / count($nums), 2) : null;
    }

    // Preliminar visible venga de donde venga: hay dos convenciones dando
    // vueltas (filas tipo=preliminar del formulario, y detalle.nota_texto de
    // la planilla) y cada pantalla solo leia la suya. Devuelve filas para el
    // formulario, priorizando las filas explicitas.
    private function preliminarVisible($filasPrelim, $notaTexto): array
    {
        $vals = [];
        foreach ((array) $filasPrelim as $n) {
            $v = is_array($n)
                ? strtoupper(trim((string) ($n['descripcion'] ?? $n['valor'] ?? '')))
                : strtoupper(trim((string) ($n->descripcion ?? $n->valor ?? '')));
            if ($v !== '' && $v !== '0' && !in_array($v, $vals, true)) $vals[] = $v;
        }
        if (!$vals) {
            $t = strtoupper(trim((string) $notaTexto));
            if ($t !== '' && $t !== '0') $vals[] = $t;
        }
        return array_map(fn ($v) => ['valor' => $v, 'descripcion' => $v], $vals);
    }

    private function normalizeFecha(?string $d): string
    {
        if (!$d) return now()->toDateString();
        try {
            return date('Y-m-d', strtotime($d));
        } catch (\Throwable $e) {
            return now()->toDateString();
        }
    }

    private function asDateYmd($raw): string
    {
        if ($raw instanceof \Carbon\CarbonInterface) {
            return $raw->toDateString();
        }
        return $this->normalizeFecha($raw ?: null);
    }

    private function normalizePeriodo($p): ?int
    {
        if ($p === '' || is_null($p)) return null;
        $n = (int)$p;
        if ($n < 0 || $n > 2) return null; // solo 0..2
        return $n;
    }

    private function normalizeCiclo($c): ?int
    {
        if ($c === '' || is_null($c)) return null;
        $n = (int)$c;
        if ($n < 2000 || $n > 2100) return null;
        return $n;
    }

    // Recalcula y persiste los promedios por materia y periodo (0, 1, 2) para los combos de anio/division/ciclo afectados.
    // Deduplica los combos, calcula promedios con AVG y limpia registros huerfanos en alumno_promedios.
    /**
     * Recalcula y persiste promedios por materia/período (0..2) para los combos tocados.
     * $combos = [['anio'=>int, 'division'=>?string, 'ciclo'=>int], ...]
     */
    private function recalcAndUpsertPromedios(int $alumnoId, array $combos): void
    {
        // Deduplica los combos de anio/division/ciclo para evitar procesar el mismo conjunto multiple veces.
        // Deduplicar combos
        $uniq = [];
        foreach ($combos as $c) {
            $k = (int)($c['anio'] ?? 0) . '|' .
                 strtoupper((string)($c['division'] ?? '')) . '|' .
                 (int)($c['ciclo'] ?? 0);
            $uniq[$k] = [
                'anio'     => (int)($c['anio'] ?? 0),
                'division' => $c['division'] ? strtoupper(trim($c['division'])) : null,
                'ciclo'    => (int)($c['ciclo'] ?? 0),
            ];
        }

        foreach ($uniq as $c) {
            if (!$c['anio'] || !$c['ciclo']) continue;

            $rows = DB::table('alumno_calificaciones')
                // TEA/TEP/TED y ceros no son notas numericas: quedan fuera del
                // promedio (AVG los contaria como 0 y arruinaria el calculo).
                ->selectRaw("materia_id, periodo, COUNT(*) as notas_count, AVG(CASE WHEN valor + 0 > 0 THEN valor + 0 END) as promedio")
                ->where('alumno_id', $alumnoId)
                ->where('anio', $c['anio'])
                ->when($c['division'], fn($q) => $q->where('division', $c['division']), fn($q) => $q->whereNull('division'))
                ->where('ciclo_lectivo', $c['ciclo'])
                ->where(function($q) {
                    $q->whereNull('tipo_calificacion')
                      ->orWhere('tipo_calificacion', 'regular');
                })
                ->groupBy('materia_id', 'periodo')
                ->get();

            $presentes = [];
            foreach ($rows as $r) {
                $p = is_numeric($r->periodo) ? (int)$r->periodo : 0;
                if (!in_array($p, [0,1,2], true)) continue;

                $presentes[] = [(int)$r->materia_id, $p];

                DB::table('alumno_promedios')->updateOrInsert(
                    [
                        'alumno_id'     => $alumnoId,
                        'materia_id'    => (int)$r->materia_id,
                        'anio'          => $c['anio'],
                        'division'      => $c['division'],
                        'ciclo_lectivo' => $c['ciclo'],
                        'periodo'       => $p,
                    ],
                    [
                        'promedio'    => round((float)$r->promedio, 2),
                        'notas_count' => (int)$r->notas_count,
                        'updated_at'  => now(),
                        'created_at'  => now(),
                    ]
                );
            }

            // Elimina los promedios que ya no tienen notas asociadas para mantener la consistencia.
            // Limpieza de promedios sin notas
            $existentes = DB::table('alumno_promedios')
                ->select('materia_id', 'periodo')
                ->where('alumno_id', $alumnoId)
                ->where('anio', $c['anio'])
                ->when($c['division'], fn($q) => $q->where('division', $c['division']), fn($q) => $q->whereNull('division'))
                ->where('ciclo_lectivo', $c['ciclo'])
                ->get();

            foreach ($existentes as $ex) {
                $still = collect($presentes)->first(fn($x) => $x[0] === (int)$ex->materia_id && $x[1] === (int)$ex->periodo);
                if (!$still) {
                    DB::table('alumno_promedios')
                        ->where('alumno_id', $alumnoId)
                        ->where('materia_id', $ex->materia_id)
                        ->where('anio', $c['anio'])
                        ->when($c['division'], fn($q) => $q->where('division', $c['division']), fn($q) => $q->whereNull('division'))
                        ->where('ciclo_lectivo', $c['ciclo'])
                        ->where('periodo', $ex->periodo)
                        ->delete();
                }
            }
        }
    }

    /**
     * Arma la planilla de un curso + materia: una sola fila por alumno con los
     * valores ya resueltos (parciales, preliminar, final de cuatrimestre,
     * intensificaciones) más el resumen del pie.
     *
     * Es la única fuente de datos de la planilla: la vista para imprimir y el
     * Excel salen de acá, así lo que se descarga coincide con lo que se ve en
     * pantalla. Antes cada uno leía la BD por su cuenta y el Excel perdía las
     * notas guardadas como texto (por ejemplo las valoraciones cualitativas).
     *
     * @return array{materia, ciclo, anio, division, profesor, filas, resumen, ventanaAbierta, ventanaAlcance, ventanaP1, ventanaP2}
     */
    private function prepararPlanilla(Request $req): array
    {
        $user = $req->user();
        if (!$user) {
            abort(401, 'No autorizado. Iniciá sesión para ver la planilla.');
        }

        $anio      = $req->integer('anio');
        $division  = strtoupper(trim((string) $req->query('division', '')));
        $materiaId = $req->integer('materia_id');
        $ciclo     = $req->integer('ciclo', date('Y'));

        if (!$anio || !$division || !$materiaId) {
            abort(422, 'Faltan parámetros: anio, division, materia_id');
        }

        // Alcance por curso: solo gestión global o profesor/preceptor asignado
        // al curso (sin_rol recibe 403). El token manual se inyecta al request
        // para reutilizar la misma regla que el resto de lecturas.
        $req->setUserResolver(fn() => $user);
        $this->assertCanReadCurso($req, $anio, $division);

        $materia = Materia::select(['id','nombre','anio'])->findOrFail($materiaId);

        // Sin DNI ni género: no se expone PII en la planilla imprimible.
        $alumnos = Alumno::where('anio', $anio)
            ->where('division', $division)
            ->orderBy('apellido')->orderBy('nombre')
            ->get(['id','nombre','apellido','grupo']);

        $alumnoIds = $alumnos->pluck('id');

        $calificaciones = AlumnoCalificacion::whereIn('alumno_id', $alumnoIds)
            ->where('materia_id', $materiaId)
            ->where('ciclo_lectivo', $ciclo)
            ->get(['alumno_id','valor','fecha','descripcion','detalle','periodo','tipo_calificacion']);

        $estados = \App\Dominios\Calificaciones\AlumnoMateriaEstado::whereIn('alumno_id', $alumnoIds)
            ->where('materia_id', $materiaId)
            ->where('ciclo_lectivo', $ciclo)
            ->get()
            ->keyBy('alumno_id');

        $calMap = [];
        foreach ($calificaciones as $c) {
            $aid = $c->alumno_id;
            $tipo = $c->tipo_calificacion ?: 'regular';
            if (!isset($calMap[$aid])) $calMap[$aid] = [];
            $calMap[$aid][] = [
                'valor'            => $this->valorLeido($c->valor),
                'descripcion'      => $c->descripcion,
                'detalle'          => $c->detalle,
                'fecha'            => $c->fecha,
                'periodo'          => (int) $c->periodo,
                'tipo_calificacion' => $tipo,
            ];
        }

        $result = [];

        // Así se imprime una nota en la planilla: vacío si no hay, número con
        // un decimal, o el texto tal cual (TEA/TEP/TED, valoraciones).
        $fmt = static function ($valor): string {
            if ($valor === null || $valor === '' || (is_numeric($valor) && (float) $valor <= 0)) return '';
            return is_numeric($valor) ? number_format((float) $valor, 1) : (string) $valor;
        };

        foreach ($alumnos as $a) {
            $notas = $calMap[$a->id] ?? [];
            $regularP1 = []; $regularP2 = [];
            $regularP1Raw = []; $regularP2Raw = [];
            $intensificacion = [];
            // Filas tipo=preliminar (las guarda el formulario): sirven de
            // respaldo si no hay nota_texto, y no van a intensificacion.
            $prelimP1 = null; $prelimP2 = null;
            $finalCuatriP1 = null; $finalCuatriP2 = null; $finalAnioPlanilla = null;
            foreach ($notas as $n) {
                $t = $n['tipo_calificacion'];
                $p = $n['periodo'];
                $entry = ['valor' => $n['valor'], 'descripcion' => $n['descripcion'] ?? null];
                if ($t === 'regular' && $p === 1) { $regularP1[] = $n['valor']; $regularP1Raw[] = $entry; }
                elseif ($t === 'regular' && $p === 2) { $regularP2[] = $n['valor']; $regularP2Raw[] = $entry; }
                elseif ($t === 'preliminar' && (int) $p === 1 && $prelimP1 === null) {
                    $prelimP1 = strtoupper(trim((string) ($n['descripcion'] ?? $n['valor'] ?? ''))) ?: null;
                }
                elseif ($t === 'preliminar' && (int) $p === 2 && $prelimP2 === null) {
                    $prelimP2 = strtoupper(trim((string) ($n['descripcion'] ?? $n['valor'] ?? ''))) ?: null;
                }
                elseif ($t === 'final_cuatrimestre' && (int) $p === 1 && $finalCuatriP1 === null) {
                    $finalCuatriP1 = $this->notaNumerica($n['valor'] ?? $n['descripcion'] ?? null);
                }
                elseif ($t === 'final_cuatrimestre' && (int) $p === 2 && $finalCuatriP2 === null) {
                    $finalCuatriP2 = $this->notaNumerica($n['valor'] ?? $n['descripcion'] ?? null);
                }
                elseif ($t === 'final_anio' && $finalAnioPlanilla === null) {
                    $finalAnioPlanilla = $this->notaNumerica($n['valor'] ?? $n['descripcion'] ?? null);
                }
                else $intensificacion[] = $n;
            }

            $est = $estados->get($a->id);

            $p1Detalle = null; $p2Detalle = null;
            foreach ($notas as $n) {
                if ($n['tipo_calificacion'] === 'regular' && $n['periodo'] === 1 && !$p1Detalle && $n['detalle']) {
                    $p1Detalle = is_string($n['detalle']) ? json_decode($n['detalle'], true) : $n['detalle'];
                }
                if ($n['tipo_calificacion'] === 'regular' && $n['periodo'] === 2 && !$p2Detalle && $n['detalle']) {
                    $p2Detalle = is_string($n['detalle']) ? json_decode($n['detalle'], true) : $n['detalle'];
                }
            }

            $califs = [
                'p1' => array_values(array_filter($regularP1Raw ?? [], fn($n) => true)),
                'p2' => array_values(array_filter($regularP2Raw ?? [], fn($n) => true)),
                // Mismo criterio que por-curso-materia: las filas mandan,
                // nota_texto es respaldo. Si no, cada pantalla muestra un valor.
                'p1_nota_texto' => $prelimP1 ?? $p1Detalle['nota_texto'] ?? null,
                'p2_nota_texto' => $prelimP2 ?? $p2Detalle['nota_texto'] ?? null,
                // Final de cuatrimestre: la guardada, el respaldo del detalle y,
                // si no hay ninguna de las dos, el promedio de las parciales. Es
                // el mismo número con el que el sistema define el estado del
                // período, así la planilla impresa no dice otra cosa.
                'p1_final' => $finalCuatriP1 ?? $p1Detalle['calificacion_final'] ?? $this->promedioDe($regularP1),
                'p2_final' => $finalCuatriP2 ?? $p2Detalle['calificacion_final'] ?? $this->promedioDe($regularP2),
                'final_anio' => ($est && $est->nota_final !== null) ? (float) $est->nota_final : $finalAnioPlanilla,
            ];

            // Las 17 celdas de la planilla oficial (H..X), ya con el texto que
            // va impreso. Lo mismo consume la vista para mostrar y el Excel.
            $celdas = [];
            foreach ([1, 2, 3, 4] as $j) {
                $i = $j - 1;
                $celdas["p1_$j"] = ($califs['p1'][$i]['descripcion'] ?? '') ?: $fmt($regularP1[$i] ?? null);
                $celdas["p2_$j"] = ($califs['p2'][$i]['descripcion'] ?? '') ?: $fmt($regularP2[$i] ?? null);
            }
            $celdas['p1_prelim'] = (string) ($califs['p1_nota_texto'] ?? '');
            $celdas['p2_prelim'] = (string) ($califs['p2_nota_texto'] ?? '');
            $celdas['p1_cuatri'] = $fmt($califs['p1_final']);
            $celdas['p2_cuatri'] = $fmt($califs['p2_final']);
            $celdas['int_1c']  = $fmt($est?->nota_intensificacion_1cuatri);
            $celdas['int_dic'] = $fmt($est?->nota_intensificacion_diciembre);
            $celdas['int_feb'] = $fmt($est?->nota_intensificacion_febrero);
            $celdas['int_mab'] = $fmt($est?->nota_intensificacion_marzo_abril);
            $celdas['final']   = $fmt($califs['final_anio']);

            $result[] = [
                'alumno_id'       => $a->id,
                'nombre'          => $a->nombre,
                'apellido'        => $a->apellido,
                'genero'          => null,
                'dni'             => null,
                'grupo'           => $a->grupo,
                'calificaciones'  => $califs,
                'celdas'          => $celdas,
                'promedio_p1'     => $this->promedioDe($regularP1),
                'promedio_p2'     => $this->promedioDe($regularP2),
                'p1_notas'        => $regularP1,
                'p2_notas'        => $regularP2,
                'intensificaciones' => $intensificacion,
                'estado'          => $est ? [
                    'p1_estado'                        => $est->p1_estado,
                    'p2_estado'                        => $est->p2_estado,
                    'estado_final'                     => $est->estado_final,
                    'nota_final'                       => $est->nota_final !== null ? (float) $est->nota_final : null,
                    'nota_intensificacion_1cuatri'     => $est->nota_intensificacion_1cuatri !== null ? (float) $est->nota_intensificacion_1cuatri : null,
                    'nota_intensificacion_diciembre'   => $est->nota_intensificacion_diciembre !== null ? (float) $est->nota_intensificacion_diciembre : null,
                    'nota_intensificacion_febrero'     => $est->nota_intensificacion_febrero !== null ? (float) $est->nota_intensificacion_febrero : null,
                    'nota_intensificacion_marzo_abril' => $est->nota_intensificacion_marzo_abril !== null ? (float) $est->nota_intensificacion_marzo_abril : null,
                ] : null,
            ];
        }

        // Pie de la planilla: cuántos aprobaron, desaprobaron y quedan sin
        // evaluar en cada columna. "pendiente" (el estado que escribe el
        // sistema cuando todavía no hay nota) cuenta como SIN EVALUAR, no como
        // desaprobado.
        $resumen = [];
        foreach (['p1', 'p2', 'fin', 'dic', 'febmar', 'mabr'] as $g) {
            $resumen[$g] = ['apro' => 0, 'desa' => 0, 'sin' => 0];
        }
        $clasificar = static function ($estado): string {
            if ($estado === null || $estado === '' || $estado === 'pendiente') return 'sin';
            return in_array($estado, ['aprobado', 'aprobada'], true) ? 'apro' : 'desa';
        };
        // Las columnas DIC / FEB / MAR-ABR llevan nota numérica, no estado.
        $clasificarNota = static function ($valor): string {
            if ($valor === null || $valor === '') return 'sin';
            return (float) $valor >= 7 ? 'apro' : 'desa';
        };
        foreach ($result as $f) {
            $e = $f['estado'];
            $resumen['p1'][$clasificar($e['p1_estado'] ?? null)]++;
            $resumen['p2'][$clasificar($e['p2_estado'] ?? null)]++;
            $resumen['fin'][$clasificar($e['estado_final'] ?? null)]++;
            $resumen['dic'][$clasificarNota($e['nota_intensificacion_diciembre'] ?? null)]++;
            $resumen['febmar'][$clasificarNota($e['nota_intensificacion_febrero'] ?? null)]++;
            $resumen['mabr'][$clasificarNota($e['nota_intensificacion_marzo_abril'] ?? null)]++;
        }
        $tablaResumen = [];
        foreach (['apro', 'desa', 'sin'] as $clave) {
            $tablaResumen[$clave] = array_map(
                static fn(string $g): int => $resumen[$g][$clave],
                ['p1', 'p2', 'fin', 'dic', 'febmar', 'mabr']
            );
        }
        $tablaResumen['total'] = array_map(
            static fn(string $g): int => $resumen[$g]['apro'] + $resumen[$g]['desa'] + $resumen[$g]['sin'],
            ['p1', 'p2', 'fin', 'dic', 'febmar', 'mabr']
        );

        // Buscar profesor asignado a esta materia/curso
        $asignacion = \App\Dominios\Profesores\ProfesorAsignacion::where('materia_id', $materiaId)
            ->where('anio', $anio)
            ->where('division', $division)
            ->with('profesor')
            ->first();

        $ventana = \App\Dominios\Calificaciones\VentanaNotaFinal::actual();

        return [
            'materia'  => $materia,
            'ciclo'    => $ciclo,
            'anio'     => $anio,
            'division' => $division,
            'profesor' => $asignacion && $asignacion->profesor
                ? $asignacion->profesor->apellido . ', ' . $asignacion->profesor->nombre
                : null,
            'filas'    => $result,
            // Filas del formulario: la plantilla de la escuela trae 35 renglones y
            // se agregan de a 5 si el curso tiene más. Es el mismo número que usa
            // PlanillaExcel, así la pantalla y el Excel coinciden.
            'filasFormulario' => \App\Support\PlanillaExcel::filasPara(count($result)),
            'resumen'  => $tablaResumen,
            'ventanaAbierta' => $ventana ? $ventana->estaAbierta() : false,
            'ventanaAlcance' => $ventana ? $ventana->alcanceNormalizado() : 'ambos',
            'ventanaP1'      => $ventana ? $ventana->permitePeriodo(1) : false,
            'ventanaP2'      => $ventana ? $ventana->permitePeriodo(2) : false,
        ];
    }

    // Devuelve la vista Blade para imprimir la planilla de calificaciones de un curso+materia.
    // GET /calificaciones/planilla?anio=&division=&materia_id=&ciclo=
    // Requiere autenticación (middleware auth.token: Bearer o ?ticket=).
    public function printPlanilla(Request $req)
    {
        $datos = $this->prepararPlanilla($req);
        $frontend = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

        return response()
            ->view('calificaciones.planilla', [
                'materia'  => $datos['materia'],
                'ciclo'    => $datos['ciclo'],
                'anio'     => $datos['anio'],
                'division' => $datos['division'],
                'alumnos'  => $datos['filas'],
                'filas'    => $datos['filasFormulario'],
                'resumen'  => $datos['resumen'],
                'profesor' => $datos['profesor'],
                'editable' => $req->boolean('editable', false),
                'ventanaAbierta' => $datos['ventanaAbierta'],
                'ventanaAlcance' => $datos['ventanaAlcance'],
                'ventanaP1' => $datos['ventanaP1'],
                'ventanaP2' => $datos['ventanaP2'],
            ])
            ->header('Content-Security-Policy', "frame-ancestors 'self' {$frontend}")
            ->header('Referrer-Policy', 'no-referrer');
    }

    // Descarga la planilla en Excel con el diseño oficial de la escuela.
    // GET /calificaciones/planilla/excel?anio=&division=&materia_id=&ciclo=
    //
    // Se descarga el Excel y no el PDF: se imprime nítido y el docente puede
    // corregir a mano lo que falte antes de firmar.
    public function descargarPlanillaExcel(Request $req)
    {
        // Los mismos datos que ve la pantalla: prepararPlanilla deja las celdas
        // ya con el texto impreso, así el Excel no dice nada distinto.
        $datos = $this->prepararPlanilla($req);
        $materia = $datos['materia'];
        $anio = $datos['anio'];
        $division = $datos['division'];
        $ciclo = $datos['ciclo'];
        $filas = $datos['filas'];

        $excel = new \App\Support\PlanillaExcel();
        $excel->armar(
            $filas,
            config('planilla'),
            $materia->nombre,
            $datos['profesor'],
            $ciclo,
            $anio,
            $division
        );
        $excel->ponerResumen($datos['resumen']);

        $nombre = 'Planilla-' . preg_replace('/[^\p{L}\p{N}]+/u', '-', $materia->nombre)
            . "-{$anio}-{$division}-{$ciclo}.xlsx";

        // Se genera una sola vez: el cuerpo y el Content-Length tienen que
        // medir lo mismo o la descarga llega cortada.
        $archivo = $excel->contenido();

        return response($archivo, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // filename= lleva solo ASCII (los nombres con acentos se dañan en
            // algunos clientes); filename*= trae el nombre tal cual.
            'Content-Disposition' => 'attachment; filename="' . $this->nombreAscii($nombre)
                . '"; filename*=UTF-8\'\'' . rawurlencode($nombre),
            'Content-Length'      => (string) strlen($archivo),
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** Nombre de archivo sin caracteres raros para el header viejo (filename=). */
    private function nombreAscii(string $nombre): string
    {
        // iconv translitera los acentos con "?" (ilegal en Windows), así que los
        // reemplazos se hacen a mano: son siempre las vocales acentuadas y la ñ.
        $traducciones = [
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ];
        $sin = preg_replace('/\.xlsx$/i', '', strtr($nombre, $traducciones));
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sin);

        return trim($ascii, '-.') . '.xlsx';
    }

    // Guarda la planilla completa desde el modo edición (todos los alumnos en una sola petición).
    // POST /calificaciones/planilla/guardar
    public function guardarPlanilla(Request $req)
    {
        // Los docentes escriben "tea" en minuscula (el frontend lo acepta):
        // se normaliza a mayusculas ANTES de validar, porque la regla `in:`
        // es sensible a mayusculas y si no toda la planilla falla con 422.
        // También se acepta coma decimal ("8,5" -> "8.5"), porque la regla
        // `numeric` rechaza la coma y si no editar con decimales da error.
        $input = $req->all();
        if (isset($input['alumnos']) && is_array($input['alumnos'])) {
            foreach ($input['alumnos'] as $i => $a) {
                foreach (['p1_preliminar', 'p2_preliminar'] as $k) {
                    if (isset($a[$k]) && is_string($a[$k])) {
                        $input['alumnos'][$i][$k] = mb_strtoupper(trim($a[$k]), 'UTF-8');
                    }
                }
                foreach (['p1_vp', 'p2_vp'] as $k) {
                    if (isset($a[$k]) && is_array($a[$k])) {
                        foreach ($a[$k] as $j => $vp) {
                            if (is_string($vp)) {
                                $input['alumnos'][$i][$k][$j] = str_replace(',', '.', trim($vp));
                            }
                        }
                    }
                }
                foreach (['p1_final_cuatri', 'p2_final_cuatri', 'int_1cuatri', 'int_dic', 'int_feb', 'int_mar_abr'] as $k) {
                    if (isset($a[$k]) && is_string($a[$k])) {
                        $input['alumnos'][$i][$k] = str_replace(',', '.', trim($a[$k]));
                    }
                }
            }
            $req->replace($input);
        }

        $data = $req->validate([
            'anio'              => ['required','integer','min:1'],
            'division'          => ['required','string','max:2'],
            'materia_id'        => ['required','integer','min:1'],
            'ciclo'             => ['required','integer','min:2000'],
            'alumnos'           => ['required','array'],
            'alumnos.*.alumno_id'     => ['required','integer','min:1'],
            'alumnos.*.p1_vp'        => ['nullable','array'],
            'alumnos.*.p1_vp.*'      => ['nullable','string','max:10'],
            'alumnos.*.p1_preliminar' => ['nullable','string','in:TEA,TEP,TED'],
            'alumnos.*.p2_vp'        => ['nullable','array'],
            'alumnos.*.p2_vp.*'      => ['nullable','string','max:10'],
            'alumnos.*.p2_preliminar' => ['nullable','string','in:TEA,TEP,TED'],
            'alumnos.*.p1_final_cuatri' => ['nullable','numeric','min:1','max:10'],
            'alumnos.*.p2_final_cuatri' => ['nullable','numeric','min:1','max:10'],
            'alumnos.*.int_1cuatri' => ['nullable','numeric'],
            'alumnos.*.int_dic'     => ['nullable','numeric'],
            'alumnos.*.int_feb'     => ['nullable','numeric'],
            'alumnos.*.int_mar_abr' => ['nullable','numeric'],
        ]);

        $anio     = (int)$data['anio'];
        $division = strtoupper(trim($data['division']));
        $materiaId = (int)$data['materia_id'];
        $ciclo    = (int)$data['ciclo'];

        // Autorización por ámbito: admin/directivo/jefe_preceptores o usuario asignado al curso.
        if (!$this->userCanWriteAll($req) && !$this->userIsAssignedTo($req, $anio, $division)) {
            abort(403, 'No tenés permiso para modificar la planilla de este curso.');
        }

        $materia = Materia::find($materiaId);
        if (!$materia) {
            return response()->json(['ok' => false, 'message' => 'Materia no encontrada'], 404);
        }

        // La materia debe pertenecer al año del curso editado.
        if ((int) $materia->anio !== $anio) {
            return response()->json(['ok' => false, 'message' => 'La materia no corresponde al año del curso.'], 422);
        }

        $errores = [];
        $gradesByStudent = [];

        $ventanaPlanilla = \App\Dominios\Calificaciones\VentanaNotaFinal::actual();
        $ventanaAbiertaPlanilla = $ventanaPlanilla ? $ventanaPlanilla->estaAbierta() : false;
        $permiteP1Planilla = $ventanaPlanilla ? $ventanaPlanilla->permitePeriodo(1) : false;
        $permiteP2Planilla = $ventanaPlanilla ? $ventanaPlanilla->permitePeriodo(2) : false;

        DB::transaction(function () use ($data, $anio, $division, $materia, $ciclo, &$errores, $req, &$gradesByStudent, $ventanaAbiertaPlanilla, $permiteP1Planilla, $permiteP2Planilla) {
            foreach ($data['alumnos'] as $aData) {
                $alumno = Alumno::find($aData['alumno_id']);
                if (!$alumno) {
                    $errores[] = 'Alumno ID ' . $aData['alumno_id'] . ': no encontrado';
                    continue;
                }

                // --- Capturar valores anteriores antes de borrar ---
                $oldCals = AlumnoCalificacion::where('alumno_id', $alumno->id)
                    ->where('materia_id', $materia->id)
                    ->where('anio', $anio)
                    ->where('division', $division)
                    ->where('ciclo_lectivo', $ciclo)
                    ->get();
                $oldSummary = $this->buildGradesSummary($oldCals);

                // Preservar finales no habilitados: la planilla no los envía
                // (inputs deshabilitados) y no deben borrarse.
                $quiereP1 = isset($aData['p1_final_cuatri']) && $aData['p1_final_cuatri'] !== null && $aData['p1_final_cuatri'] !== '';
                $quiereP2 = isset($aData['p2_final_cuatri']) && $aData['p2_final_cuatri'] !== null && $aData['p2_final_cuatri'] !== '';
                if ((!$permiteP1Planilla && $quiereP1) || (!$permiteP2Planilla && $quiereP2)) {
                    $errores[] = 'Alumno ID ' . $aData['alumno_id'] . ': esa nota final de cuatrimestre no está habilitada por la ventana actual';
                    continue;
                }
                $preservarFinales = $oldCals->filter(function ($c) use ($permiteP1Planilla, $permiteP2Planilla, $ventanaAbiertaPlanilla) {
                    if ($c->tipo_calificacion === 'final_cuatrimestre') {
                        if ((int) $c->periodo === 1) return !$permiteP1Planilla;
                        if ((int) $c->periodo === 2) return !$permiteP2Planilla;
                        return true;
                    }
                    if ($c->tipo_calificacion === 'final_anio') return !$ventanaAbiertaPlanilla;
                    return false;
                })->values();

                // --- Siempre borrar todos los registros existentes primero ---
                AlumnoCalificacion::where('alumno_id', $alumno->id)
                    ->where('materia_id', $materia->id)
                    ->where('anio', $anio)
                    ->where('division', $division)
                    ->where('ciclo_lectivo', $ciclo)
                    ->delete();

                $entradas = [];

                // --- 1° Cuatrimestre ---
                $p1Notas = [];
                foreach (($aData['p1_vp'] ?? []) as $vp) {
                    $vp = trim($vp);
                    if ($vp !== '') {
                        $v = is_numeric($vp) ? max(1, (float)$vp) : null;
                        $p1Notas[] = ['valor' => $v, 'descripcion' => strtoupper($vp)];
                    }
                }
                $p1Prelim = trim($aData['p1_preliminar'] ?? '');
                if (!empty($p1Notas) || $p1Prelim !== '') {
                    $detalle = [];
                    if ($p1Prelim !== '') $detalle['nota_texto'] = strtoupper($p1Prelim);
                    $vPrelim = is_numeric($p1Prelim) ? max(1, (float)$p1Prelim) : null;
                    $notas = !empty($p1Notas) ? $p1Notas : [['valor' => $vPrelim, 'descripcion' => strtoupper($p1Prelim)]];
                    $entradas[] = [
                        'anio' => $anio, 'division' => $division,
                        'ciclo_lectivo' => $ciclo, 'periodo' => 1,
                        'materia_id' => $materia->id, 'notas' => $notas,
                        'detalle' => !empty($detalle) ? json_encode($detalle) : null,
                    ];
                }

                // --- 2° Cuatrimestre ---
                $p2Notas = [];
                foreach (($aData['p2_vp'] ?? []) as $vp) {
                    $vp = trim($vp);
                    if ($vp !== '') {
                        $v = is_numeric($vp) ? max(1, (float)$vp) : null;
                        $p2Notas[] = ['valor' => $v, 'descripcion' => strtoupper($vp)];
                    }
                }
                $p2Prelim = trim($aData['p2_preliminar'] ?? '');
                if (!empty($p2Notas) || $p2Prelim !== '') {
                    $detalle = [];
                    if ($p2Prelim !== '') $detalle['nota_texto'] = strtoupper($p2Prelim);
                    $vPrelim = is_numeric($p2Prelim) ? max(1, (float)$p2Prelim) : null;
                    $notas = !empty($p2Notas) ? $p2Notas : [['valor' => $vPrelim, 'descripcion' => strtoupper($p2Prelim)]];
                    $entradas[] = [
                        'anio' => $anio, 'division' => $division,
                        'ciclo_lectivo' => $ciclo, 'periodo' => 2,
                        'materia_id' => $materia->id, 'notas' => $notas,
                        'detalle' => !empty($detalle) ? json_encode($detalle) : null,
                    ];
                }

                // --- Intensificaciones ---
                $intMap = [
                    'intensificacion_1cuatri'        => $aData['int_1cuatri'] ?? null,
                    'intensificacion_diciembre'      => $aData['int_dic'] ?? null,
                    'intensificacion_febrero'        => $aData['int_feb'] ?? null,
                    'intensificacion_marzo_abril'    => $aData['int_mar_abr'] ?? null,
                ];
                foreach ($intMap as $tipo => $val) {
                    $v = is_numeric($val) ? max(1, (float)$val) : null;
                    if ($v !== null) {
                        $entradas[] = [
                            'anio' => $anio, 'division' => $division,
                            'ciclo_lectivo' => $ciclo, 'periodo' => 0,
                            'tipo_calificacion' => $tipo,
                            'materia_id' => $materia->id,
                            'notas' => [['valor' => $v]],
                        ];
                    }
                }

                // --- Notas finales de cuatrimestre (respetan el alcance de la ventana) ---
                $toFloat = fn ($raw) => ($raw !== null && $raw !== '') ? (float) str_replace(',', '.', trim((string) $raw)) : null;
                $p1FinalCuatri = (isset($aData['p1_final_cuatri']) && $aData['p1_final_cuatri'] !== null && $aData['p1_final_cuatri'] !== '') ? $toFloat($aData['p1_final_cuatri']) : null;
                $p2FinalCuatri = (isset($aData['p2_final_cuatri']) && $aData['p2_final_cuatri'] !== null && $aData['p2_final_cuatri'] !== '') ? $toFloat($aData['p2_final_cuatri']) : null;
                if ($permiteP1Planilla && $p1FinalCuatri !== null) {
                    $entradas[] = [
                        'anio' => $anio, 'division' => $division,
                        'ciclo_lectivo' => $ciclo, 'periodo' => 1,
                        'tipo_calificacion' => 'final_cuatrimestre',
                        'materia_id' => $materia->id,
                        'notas' => [['valor' => max(1, min(10, $p1FinalCuatri))]],
                    ];
                }
                if ($permiteP2Planilla && $p2FinalCuatri !== null) {
                    $entradas[] = [
                        'anio' => $anio, 'division' => $division,
                        'ciclo_lectivo' => $ciclo, 'periodo' => 2,
                        'tipo_calificacion' => 'final_cuatrimestre',
                        'materia_id' => $materia->id,
                        'notas' => [['valor' => max(1, min(10, $p2FinalCuatri))]],
                    ];
                }
                // Nota final del año automática: (final1 + final2) / 2, considerando
                // valores preservados para los períodos no habilitados.
                if ($ventanaAbiertaPlanilla) {
                    $oldP1 = null; $oldP2 = null;
                    foreach ($oldCals as $oc) {
                        if ($oc->tipo_calificacion === 'final_cuatrimestre' && (int) $oc->periodo === 1 && $oldP1 === null) {
                            $vv = $this->notaNumerica($oc->valor);
                            if ($vv !== null) $oldP1 = $vv;
                        }
                        if ($oc->tipo_calificacion === 'final_cuatrimestre' && (int) $oc->periodo === 2 && $oldP2 === null) {
                            $vv = $this->notaNumerica($oc->valor);
                            if ($vv !== null) $oldP2 = $vv;
                        }
                    }
                    $calcP1 = $permiteP1Planilla ? $p1FinalCuatri : $oldP1;
                    $calcP2 = $permiteP2Planilla ? $p2FinalCuatri : $oldP2;
                    if ($calcP1 !== null && $calcP2 !== null) {
                        $entradas[] = [
                            'anio' => $anio, 'division' => $division,
                            'ciclo_lectivo' => $ciclo, 'periodo' => 0,
                            'tipo_calificacion' => 'final_anio',
                            'materia_id' => $materia->id,
                            'notas' => [['valor' => round(($calcP1 + $calcP2) / 2, 2)]],
                        ];
                    }
                }
                if (!empty($preservarFinales) && $preservarFinales->isNotEmpty()) {
                    // Reinsertar finales de períodos no habilitados.
                    foreach ($preservarFinales as $pf) {
                        AlumnoCalificacion::insert([[
                            'alumno_id' => $alumno->id,
                            'materia_id' => $materia->id,
                            'anio' => $anio,
                            'division' => $division,
                            'valor' => $pf->valor,
                            'descripcion' => $pf->descripcion,
                            'ciclo_lectivo' => $ciclo,
                            'periodo' => $pf->periodo,
                            'tipo_calificacion' => $pf->tipo_calificacion,
                            'detalle' => $pf->detalle,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]]);
                    }
                    $touched[] = [
                        'anio' => $anio, 'division' => $division,
                        'ciclo' => $ciclo, 'tipo' => 'final_cuatrimestre',
                        'materia_id' => $materia->id,
                    ];
                }

                // --- Insertar entradas nuevas ---
                $touched = [];
                foreach ($entradas as $e) {
                    $perAnio  = (int)$e['anio'];
                    $perDiv   = $e['division'] ?? null;
                    $perCiclo = (int)$e['ciclo_lectivo'];
                    $periodo  = (int)$e['periodo'];
                    $tipo     = $e['tipo_calificacion'] ?? 'regular';

                    $detalle = null;
                    if (!empty($e['detalle'])) {
                        $detalle = is_string($e['detalle']) ? json_decode($e['detalle'], true) : $e['detalle'];
                    }
                    $insert = [];
                    foreach ($e['notas'] as $n) {
                        $desc  = $n['descripcion'] ?? null;
                        // TEA/TEP/TED llegan con valor null: se guarda el texto
                        // (la columna es varchar) en vez de un 0 que confunde.
                        // Se recorta a 10 por el ancho de la columna.
                        $valor = $n['valor'] ?? null;
                        if ($valor === null && is_string($desc) && $desc !== '') {
                            $valor = mb_substr($desc, 0, 10);
                        } else {
                            $valor = (float)($valor ?? 0);
                        }
                        $insert[] = [
                            'alumno_id'        => $alumno->id,
                            'materia_id'       => $materia->id,
                            'anio'             => $perAnio,
                            'division'         => $perDiv,
                            'valor'            => $valor,
                            'descripcion'      => $desc,
                            'ciclo_lectivo'    => $perCiclo,
                            'periodo'          => $periodo,
                            'tipo_calificacion' => $tipo,
                            'detalle'          => $detalle ? json_encode($detalle) : null,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ];
                    }
                    if (!empty($insert)) AlumnoCalificacion::insert($insert);

                    $touched[] = [
                        'anio' => $perAnio, 'division' => $perDiv,
                        'ciclo' => $perCiclo, 'tipo' => $tipo,
                        'materia_id' => $materia->id,
                    ];
                }

                if (!empty($touched)) {
                    $this->recalcAndUpsertPromedios($alumno->id, $touched);
                    $this->actualizarEstadoMaterias($alumno->id, $touched);
                } else {
                    // Sin datos: recalcular con estado vacío
                    $emptyTouch = [[
                        'anio' => $anio, 'division' => $division,
                        'ciclo' => $ciclo, 'tipo' => 'regular',
                        'materia_id' => $materia->id,
                    ]];
                    $this->recalcAndUpsertPromedios($alumno->id, $emptyTouch);
                    $this->actualizarEstadoMaterias($alumno->id, $emptyTouch);
                }

                $newSummary = $this->buildGradesSummaryFromInput($aData);

                // Acumular cambios por alumno para registrar UNA sola entrada en el historial.
                if ($oldSummary != $newSummary) {
                    $key = $alumno->apellido . ', ' . $alumno->nombre;
                    $gradesByStudent[$key] = ['old' => $oldSummary, 'new' => $newSummary];
                }
            }

            // Registrar UNA sola entrada en el historial con todos los cambios de la planilla.
            if (!empty($gradesByStudent)) {
                $oldValues = [];
                $newValues = [];
                $allCleared = true;
                foreach ($gradesByStudent as $nombre => $g) {
                    $oldValues[$nombre] = $g['old'];
                    $newValues[$nombre] = $g['new'];
                    if (!empty($g['new'])) $allCleared = false;
                }

                $label = \App\Support\CourseLabel::label($anio, $division);
                $action = $allCleared
                    ? 'Limpió planilla de ' . $label . ' - ' . $materia->nombre
                    : 'Modificó planilla de ' . $label . ' - ' . $materia->nombre;

                ActivityLogger::log($req, $action, 'calificaciones.update', 'Alumno', null, $oldValues ?: null, $newValues ?: null);
            }
        });

        if (empty($errores)) {
            return response()->json(['ok' => true, 'message' => 'Guardado correctamente']);
        }
        return response()->json(['ok' => false, 'message' => 'Algunos alumnos no se guardaron', 'errores' => $errores], 422);
    }

    /**
     * Construye un resumen de calificaciones desde una colección de AlumnoCalificacion.
     * Se usa para capturar los valores viejos antes de guardar.
     */
    private function buildGradesSummary($cals): array
    {
        $summary = [];
        $p1Vals = [];
        $p2Vals = [];
        $p1Prelim = null;
        $p2Prelim = null;

        foreach ($cals as $c) {
            $tipo = $c->tipo_calificacion ?: 'regular';
            $p = $c->periodo;
            $desc = $c->descripcion ?: (string)$c->valor;

            if ($tipo === 'regular') {
                if ($p === 1) {
                    $p1Vals[] = $desc;
                    if ($c->detalle && !empty($c->detalle['nota_texto'])) {
                        $p1Prelim = $c->detalle['nota_texto'];
                    }
                } elseif ($p === 2) {
                    $p2Vals[] = $desc;
                    if ($c->detalle && !empty($c->detalle['nota_texto'])) {
                        $p2Prelim = $c->detalle['nota_texto'];
                    }
                }
            } elseif ($tipo === 'preliminar') {
                if ($p === 1) $summary['p1_preliminar_notas'] = $desc;
                elseif ($p === 2) $summary['p2_preliminar_notas'] = $desc;
            } elseif ($tipo === 'final_anio') {
                $summary['final_anio'] = $desc;
            } elseif ($tipo === 'final_cuatrimestre') {
                if ((int) $p === 1) $summary['p1_final_cuatri'] = (string) $c->valor;
                elseif ((int) $p === 2) $summary['p2_final_cuatri'] = (string) $c->valor;
            } elseif (str_contains($tipo, '1cuatri')) {
                $summary['int_1cuatri'] = (string)$c->valor;
            } elseif (str_contains($tipo, 'febrero')) {
                $summary['int_feb'] = (string)$c->valor;
            } elseif (str_contains($tipo, 'marzo_abril')) {
                $summary['int_mar_abr'] = (string)$c->valor;
            } elseif (str_contains($tipo, 'diciembre')) {
                $summary['int_dic'] = (string)$c->valor;
            }
        }

        if (!empty($p1Vals)) $summary['p1_notas'] = implode(', ', $p1Vals);
        if ($p1Prelim !== null) $summary['p1_preliminar'] = $p1Prelim;
        if (!empty($p2Vals)) $summary['p2_notas'] = implode(', ', $p2Vals);
        if ($p2Prelim !== null) $summary['p2_preliminar'] = $p2Prelim;

        return $summary;
    }

    /**
     * Construye un resumen de calificaciones desde los datos enviados por el frontend.
     * Se usa para capturar los valores nuevos al guardar.
     */
    private function buildGradesSummaryFromInput(array $aData): array
    {
        $summary = [];

        $p1Vps = array_filter($aData['p1_vp'] ?? [], fn($v) => trim($v) !== '');
        if (!empty($p1Vps)) $summary['p1_notas'] = implode(', ', $p1Vps);
        $p1Prelim = trim($aData['p1_preliminar'] ?? '');
        if ($p1Prelim !== '') $summary['p1_preliminar'] = $p1Prelim;

        $p2Vps = array_filter($aData['p2_vp'] ?? [], fn($v) => trim($v) !== '');
        if (!empty($p2Vps)) $summary['p2_notas'] = implode(', ', $p2Vps);
        $p2Prelim = trim($aData['p2_preliminar'] ?? '');
        if ($p2Prelim !== '') $summary['p2_preliminar'] = $p2Prelim;

        $p1f = $aData['p1_final_cuatri'] ?? null;
        if ($p1f !== null && $p1f !== '') $summary['p1_final_cuatri'] = (string) $p1f;
        $p2f = $aData['p2_final_cuatri'] ?? null;
        if ($p2f !== null && $p2f !== '') $summary['p2_final_cuatri'] = (string) $p2f;
        if (isset($p1f, $p2f) && $p1f !== '' && $p2f !== '' && is_numeric($p1f) && is_numeric($p2f)) {
            $summary['final_anio'] = (string) round(((float) $p1f + (float) $p2f) / 2, 2);
        }

        foreach (['int_1cuatri', 'int_dic', 'int_feb', 'int_mar_abr'] as $k) {
            $v = $aData[$k] ?? null;
            if ($v !== null && $v !== '') $summary[$k] = (string)$v;
        }

        return $summary;
    }
}


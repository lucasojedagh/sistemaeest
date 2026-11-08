<?php

namespace App\Dominios\Cursos;

use App\Http\Controllers\Controller;

use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\HorarioCelda;
use App\Dominios\Cursos\Materia;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\SlotKey;
use App\Support\ActivityLogger;

// Controlador para gestionar los horarios de los cursos (grilla de materias por dia y modulo horario).
class HorarioController extends Controller
{
    use \App\Support\AuthorizesScope;
    // ===== Helpers =====
    private const DIAS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie'];

    // Por compatibilidad dejamos una “base” conocida, pero NO limita:
    // más abajo unificamos con lo que haya en BD/payload.
    // Incluye h9 (12:00-12:50) para que la fila aparezca aunque no tenga celdas.
    private const BASE_SLOTS = ['h1','h2','h3','h4','h5','h6','h7','h8','h9'];

    // Convierte el numero de dia (1-5) a su clave textual (Lun-Vie).
    private function diaToKey(int $dia): string { return self::DIAS[$dia] ?? 'Lun'; }
    // Convierte la clave textual de dia (Lun-Vie) a su numero (1-5).
    private function keyToDia(string $k): int   { return (int) array_search($k, self::DIAS, true); }

    /** Ordena claves tipo hN por su número */
    private function sortSlots(array $slots): array
    {
        $slots = array_values(array_unique(array_filter($slots)));
        usort($slots, function($a, $b) {
            $ia = (int) preg_replace('/\D+/', '', (string) $a);
            $ib = (int) preg_replace('/\D+/', '', (string) $b);
            return $ia <=> $ib;
        });
        return $slots;
    }

    /**
     * Unifica todos los slots que debemos considerar:
     * - BASE_SLOTS (compat)
     * - los presentes en $rows (BD)
     * - los presentes en $payload (request)
     */
    private function collectAllSlots($rows = [], array $payload = []): array
    {
        $found = [];

        // base conocida
        foreach (self::BASE_SLOTS as $s) $found[] = $s;

        // de BD
        foreach ($rows as $r) {
            $norm = SlotKey::normalize($r->slot_key ?? null);
            if ($norm) $found[] = $norm;
        }

        // del payload
        foreach (array_keys($payload) as $k) {
            $norm = SlotKey::normalize($k);
            if ($norm) $found[] = $norm;
        }

        return $this->sortSlots($found);
    }

    /**
     * Empaqueta filas de BD a la grilla que consume el front:
     * {
     *   h1: { Lun: { materia, profesorId }, Mar: {...}, ... },
     *   h2: { ... },
     *   ...
     * }
     * Incluye TODOS los slots detectados (h1..h8 y los que aparezcan).
     */
    private function packGrid($rows, array $payloadForSlots = []): array
    {
        $allSlots = $this->collectAllSlots($rows, $payloadForSlots);

        $out = [];
        foreach ($allSlots as $s) $out[$s] = [];

        foreach ($rows as $r) {
            $slot = SlotKey::normalize($r->slot_key ?? null);
            if (!$slot) continue;
            $diaK = $this->diaToKey((int) $r->dia);

            $out[$slot][$diaK] = [
                'materia'    => $r->materia?->nombre,
                'profesorId' => $r->profesor_id, // el front tolera profesorId o profesor_id
                'horaInicio' => $r->hora_inicio ? substr((string) $r->hora_inicio, 0, 5) : null,
                'horaFin'    => $r->hora_fin ? substr((string) $r->hora_fin, 0, 5) : null,
            ];
        }
        return $out;
    }

    /**
     * Devuelve el curso (anio+division) SIN crearlo.
     *
     * Antes usaba firstOrCreate desde un GET: abrir la pantalla de horarios
     * alcanzaba para crear cursos en la base (efecto colateral de una lectura)
     * y, si el anio venia fuera de rango, MySQL terminaba en un 500.
     * Ahora es solo busqueda: devuelve null si el curso no existe.
     */
    private function requireCurso(int $anio, string $division): ?Curso
    {
        return Curso::where('anio', $anio)
            ->where('division', strtoupper($division))
            ->first();
    }

    // ===== Endpoints =====

    // GET /api/horarios/{anio}/{division}?grupo=A
    // Devuelve la grilla horaria de un curso, con las celdas agrupadas por modulo horario y dia.
    // El parametro opcional 'grupo' permite filtrar por grupo (A, B o null).
    public function show(Request $request, int $anio, string $division): JsonResponse
    {
        // Alcance de lectura del área de cursos sobre este curso.
        $this->assertCanReadCurso($request, $anio, $division, 'cursos');
        $curso = $this->requireCurso($anio, $division);

        // Curso inexistente: se avisa con un mensaje claro en vez de fallar.
        if (!$curso) {
            // Si el anio o la division estan fuera de rango, la etiqueta
            // canonica viene vacia: se muestran los valores tal cual llegan
            // para que la persona sepa que se pidió.
            $label = \App\Support\CourseLabel::label($anio, $division);
            if ($label === '') {
                $label = trim($anio . ' ' . strtoupper($division));
            }
            return response()->json([
                'message' => 'El curso ' . $label . ' no existe.',
            ], 404);
        }

        $grupo = $request->query('grupo'); // null, 'A' or 'B'
        // '?grupo=' vacío llegaba como '' y el where grupo='' no matcheaba
        // las filas guardadas con NULL: se normaliza a null.
        if ($grupo === '' || $grupo === null) {
            $grupo = null;
        } elseif (!in_array($grupo, ['A', 'B'], true)) {
            return response()->json(['message' => 'Grupo inválido. Usá A, B o ninguno.'], 422);
        }

        $rows = HorarioCelda::with('materia')
            ->where('curso_id', $curso->id)
            ->where('grupo', $grupo)
            ->get();

        return response()->json($this->packGrid($rows));
    }

    /**
     * GET /horarios/planilla?anio=&division=&grupo=&ciclo=&ticket=
     * Devuelve una vista Blade imprimible del horario de un curso (siempre con datos guardados).
     * Requiere autenticación (middleware auth.token: Bearer o ?ticket= de un solo uso).
     */
    public function printHorario(Request $req)
    {
        $user = $req->user();
        if (!$user) {
            abort(401, 'No autorizado. Iniciá sesión para ver el horario.');
        }
        $this->assertPuedeImprimir($req);

        $anio     = $req->integer('anio');
        $division = strtoupper(trim((string) $req->query('division', '')));

        if (!$anio || !$division) {
            abort(422, 'Faltan parámetros: anio, division');
        }

        // El token manual se inyecta al request para reutilizar la misma regla
        // de alcance que el resto de las lecturas.
        $req->setUserResolver(fn() => $user);
        $this->assertCanReadCurso($req, $anio, $division, 'cursos');
        $grupoRaw = strtoupper(trim((string) $req->query('grupo', '')));
        $grupo    = in_array($grupoRaw, ['A', 'B'], true) ? $grupoRaw : null;
        $ciclo    = $req->integer('ciclo', date('Y'));

        $curso = $this->requireCurso($anio, $division);

        if (!$curso) {
            abort(404, 'El curso solicitado no existe.');
        }

        $rows = HorarioCelda::with(['materia', 'profesor'])
            ->where('curso_id', $curso->id)
            ->where('grupo', $grupo)
            ->get();

        // Orden de módulos (igual que el frontend) y etiquetas de hora por defecto.
        $slotOrder = ['h1', 'h2', 'h3', 'h8', 'h9', 'h4', 'h5', 'h6', 'h7'];
        $slotLabels = [
            'h1' => '07:30 – 08:30', 'h2' => '08:40 – 09:40', 'h3' => '09:50 – 10:50',
            'h8' => '11:00 – 12:00', 'h9' => '12:00 – 12:50', 'h4' => '13:05 – 14:05',
            'h5' => '14:10 – 15:10', 'h6' => '15:15 – 16:15', 'h7' => '16:20 – 17:20',
        ];
        $dias = [1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie'];

        $usedSlots = [];
        foreach ($rows as $r) {
            $s = \App\Support\SlotKey::normalize($r->slot_key);
            if ($s && !in_array($s, $usedSlots, true)) {
                $usedSlots[] = $s;
            }
        }
        $slots = array_values(array_filter($slotOrder, fn($s) => in_array($s, $usedSlots, true)));
        if (empty($slots)) {
            $slots = $slotOrder;
        }

        $grid = [];
        foreach ($slots as $s) {
            $cells = [];
            foreach ($dias as $dn => $dk) {
                $row = $rows->first(function ($r) use ($s, $dn) {
                    return \App\Support\SlotKey::normalize($r->slot_key) === $s && (int) $r->dia === $dn;
                });
                if ($row) {
                    $prof = $row->profesor;
                    $profName = $prof ? trim(($prof->nombre ?? '') . ' ' . ($prof->apellido ?? '')) : '';
                    $cells[$dk] = [
                        'materia'     => $row->materia?->nombre,
                        'profesor'    => $profName,
                        'horaInicio'  => $row->hora_inicio ? substr((string) $row->hora_inicio, 0, 5) : null,
                        'horaFin'     => $row->hora_fin ? substr((string) $row->hora_fin, 0, 5) : null,
                    ];
                } else {
                    $cells[$dk] = null;
                }
            }
            $grid[] = [
                'key'   => $s,
                'label' => $slotLabels[$s] ?? $s,
                'cells' => $cells,
            ];
        }

        $viewData = [
            'anio'     => $anio,
            'division' => $division,
            'grupo'    => $grupo,
            'ciclo'    => $ciclo,
            'dias'     => array_values($dias),
            'grid'     => $grid,
        ];

        // Si se solicita descarga, generamos un PDF real (dompdf).
        if ($req->query('download') === '1') {
            $html = view('horarios.planilla', $viewData)->render();
            $filename = 'horario_' . $anio . '_' . $division . ($grupo ? '_' . $grupo : '') . '.pdf';
            return Pdf::loadHTML($html)
                ->setPaper('A4', 'landscape')
                ->download($filename);
        }

        return response()->view('horarios.planilla', $viewData);
    }

    /**
     * PUT /api/horarios/{anio}/{division}
     * Body esperado:
     * {
     *   "h1": { "Lun": {"materia":"Lengua","profesor_id": 12}, "Mar": {...}, ... },
     *   "h2": { ... }, ...
     * }
     * (Acepta profesor_id o profesorId).
     */
    // Guarda o actualiza la grilla horaria completa de un curso.
    // Crea materias nuevas si no existen; elimina celdas vacias. Todo dentro de una transaccion.
    public function update(Request $request, int $anio, string $division): JsonResponse
    {
        $this->assertCanWriteDivision($request, $anio, $division);
        $curso     = $this->requireCurso($anio, $division);

        // Curso inexistente: se avisa con un mensaje claro en vez de fallar.
        if (!$curso) {
            $label = \App\Support\CourseLabel::label($anio, $division);
            if ($label === '') {
                $label = trim($anio . ' ' . strtoupper($division));
            }
            return response()->json([
                'message' => 'El curso ' . $label . ' no existe.',
            ], 404);
        }

        $payload   = $request->validate([
            'grupo' => ['nullable', 'string', 'in:A,B'],
        ]);
        $payload = array_merge($request->json()->all(), ['grupo' => $payload['grupo'] ?? null]);
        $grupo = $payload['grupo'] ?? null;
        $validDias = array_values(self::DIAS);   // ['Lun','Mar','Mie','Jue','Vie']

        // Profesores existentes: la columna profesor_id no tiene clave foránea,
        // así que un id inventado se guardaba como huérfano y la planilla
        // imprimía una celda sin docente. Se valida antes de tocar la base.
        $profesoresValidos = DB::table('profesores')->pluck('id')->all();

        // Hora manual del módulo: la columna es TIME y un texto suelto
        // ("ocho", "8am") reventaba con un 500 dentro de la transacción.
        $horaValida = fn($v) => is_string($v)
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v) === 1;

        // Unificamos TODOS los slots que debemos procesar (BASE + payload)
        $allSlots = $this->collectAllSlots([], $payload);

        // Se valida la grilla entera antes de escribir: así una celda mala
        // devuelve 422 y no deja el horario guardado a medias.
        $errores = [];
        foreach ($allSlots as $slotRaw) {
            $diasPayload = $payload[$slotRaw] ?? $payload[SlotKey::normalize($slotRaw)] ?? [];
            if (!is_array($diasPayload)) continue;

            foreach ($diasPayload as $diaKey => $celda) {
                if (!in_array($diaKey, $validDias, true)) continue;
                if (!is_array($celda) || trim((string)($celda['materia'] ?? '')) === '') continue;

                $etiqueta = $diaKey . ' ' . SlotKey::normalize($slotRaw);

                $profRaw = $celda['profesor_id'] ?? $celda['profesorId'] ?? null;
                if ($profRaw !== null && $profRaw !== '' && !in_array((int)$profRaw, $profesoresValidos, true)) {
                    $errores[] = 'El profesor indicado en ' . $etiqueta . ' no existe.';
                }

                foreach (['horaInicio' => 'inicio', 'horaFin' => 'fin'] as $campo => $nombre) {
                    $valor = $celda[$campo] ?? null;
                    if (($valor !== null && $valor !== '') && !$horaValida($valor)) {
                        $errores[] = 'La hora de ' . $nombre . ' de ' . $etiqueta . ' tiene que ser HH:MM (ej. 07:30).';
                    }
                }
            }
        }

        if ($errores) {
            return response()->json([
                'message' => $errores[0],
                'errors'  => ['horario' => array_values(array_unique($errores))],
            ], 422);
        }

        // Materias del año indexadas por nombre normalizado. Hay homónimas que solo
        // cambian de especialidad ("DIBUJO TECNOLÓGICO" en electromecánica y en MMO):
        // se ordena para que la del curso quede la última y gane la colisión en keyBy.
        $normName  = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
        $espCurso  = is_string($curso->especialidad) && $curso->especialidad !== '' ? $curso->especialidad : null;
        $materias  = Materia::where('anio', $anio)->get();
        $matByName = $materias
            ->sortBy(fn($m) => $espCurso !== null && $m->especialidad === $espCurso ? 1 : 0)
            ->keyBy(fn($m) => $normName((string) $m->nombre));

        DB::transaction(function () use ($payload, $validDias, $curso, $matByName, $anio, $normName, $allSlots, $grupo) {

            foreach ($allSlots as $slotRaw) {
                $slot = SlotKey::normalize($slotRaw);
                if (!$slot) continue;

                $dias = $payload[$slotRaw] ?? $payload[$slot] ?? [];
                if (!is_array($dias)) continue;

                foreach ($dias as $diaKey => $celda) {
                    // aceptar solo claves de día válidas
                    if (!in_array($diaKey, $validDias, true)) continue;

                    // Resolver día numérico (1..5)
                    $dia = array_search($diaKey, self::DIAS, true);
                    if (!$dia) continue;

                    // Celda vacía => borrar si existía
                    $isEmpty =
                        $celda === null ||
                        $celda === '' ||
                        (is_array($celda) && trim((string)($celda['materia'] ?? '')) === '');

                    $whereBase = [
                        'curso_id' => $curso->id,
                        'dia'      => $dia,
                        'slot_key' => $slot,
                        'grupo'    => $grupo,
                    ];

                    if ($isEmpty) {
                        HorarioCelda::where($whereBase)->delete();
                        continue;
                    }

                    // Extraer datos (acepta profesor_id o profesorId)
                    $materiaNombre = trim((string)($celda['materia'] ?? ''));
                    $profRaw       = $celda['profesor_id'] ?? $celda['profesorId'] ?? null;
                    $profesorId    = ($profRaw === '' || $profRaw === null) ? null : (int)$profRaw;

                    // Hora manual del modulo (inicio/fin), si se envían
                    $horaInicio = !empty($celda['horaInicio']) ? $celda['horaInicio'] : null;
                    $horaFin    = !empty($celda['horaFin']) ? $celda['horaFin'] : null;

                    if ($materiaNombre === '') {
                        HorarioCelda::where($whereBase)->delete();
                        continue;
                    }

                    // Resolver/crear materia del año por nombre normalizado
                    $key     = $normName($materiaNombre);
                    $materia = $matByName[$key] ?? null;

                    if (!$materia) {
                        $materia = Materia::create([
                            'anio'   => $anio,
                            'nombre' => $materiaNombre,
                            'activo' => 1,
                        ]);
                        $matByName[$key] = $materia;
                    }

                    // Upsert (slot_key normalizado, con grupo)
                    HorarioCelda::updateOrCreate(
                        [
                            'curso_id' => $curso->id,
                            'dia'      => $dia,
                            'slot_key' => $slot,
                            'grupo'    => $grupo,
                        ],
                        [
                            'materia_id'  => $materia->id,
                            'profesor_id' => $profesorId,
                            'hora_inicio' => $horaInicio,
                            'hora_fin'    => $horaFin,
                        ]
                    );
                }
            }
        });

        ActivityLogger::log($request, 'Actualizó horario de ' . \App\Support\CourseLabel::label($anio, $division) . ($grupo ? ' (Grupo ' . $grupo . ')' : ''), 'horario.update', 'Curso', $curso->id);

        // Si el guardado creó materias nuevas, invalida la cache de /materias/todas.
        Cache::forget('materias.todas');

        // Devolvemos la grilla normalizada (incluyendo todos los slots presentes)
        $rows = HorarioCelda::with('materia')
            ->where('curso_id', $curso->id)
            ->where('grupo', $grupo)
            ->get();

        return response()->json($this->packGrid($rows, $payload));
    }
}


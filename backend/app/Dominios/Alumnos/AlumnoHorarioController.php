<?php

namespace App\Dominios\Alumnos;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\SlotKey; // (app/Support/SlotKey.php)
use App\Support\ActivityLogger;

// Controlador para la gestion del horario personalizado de cada alumno.
// Combina el horario base del curso con overrides individuales y overlays
// de materias recursadas o en intensificacion, resolviendo el horario efectivo.
class AlumnoHorarioController extends Controller
{
    use \App\Support\AuthorizesScope;
    /* ------------------------ Helpers internos ------------------------ */

    private function splitCursoFromAlumno(object $alumno): array
    {
        $raw = trim((string)($alumno->anio ?? ''));
        $div = trim((string)($alumno->division ?? ''));

        if ($raw !== '') {
            $parts = preg_split('/\s+/', $raw);
            $a = isset($parts[0]) ? (int)preg_replace('/\D+/', '', $parts[0]) : null;
            $d = isset($parts[1]) ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $parts[1])) : null;
            if ($a) return [$a, $d ?: ($div ?: null)];
        }

        $a = (int)preg_replace('/\D+/', '', (string)($alumno->anio ?? ''));
        $d = $div ? strtoupper($div) : null;
        return [$a ?: null, $d];
    }

    private function findCurso(?int $anio, ?string $division)
    {
        if (!$anio) return null;
        $q = DB::table('cursos')->where('anio', $anio);
        if ($division) $q->where('division', $division);
        return $q->first();
    }

    // Obtiene el nombre completo del profesor a partir de su ID, o null si no existe.
    /** Devuelve nombre + apellido del profesor por id (o null). */
    private function profesorNombreById($id)
    {
        if (!$id) return null;
        return DB::table('profesores')
            ->where('id', $id)
            ->selectRaw("TRIM(CONCAT_WS(' ', nombre, apellido)) as full_name")
            ->value('full_name');
    }

    private function sameMateria(?int $idA, ?int $idB, ?string $nomA, ?string $nomB): bool
    {
        if ($idA && $idB) return (int)$idA === (int)$idB;
        $na = $this->norm($nomA);
        $nb = $this->norm($nomB);
        return $na !== '' && $na === $nb;
    }

    private function norm(?string $s): string
    {
        $s = $s ?? '';
        $s = iconv('UTF-8','ASCII//TRANSLIT',$s);
        $s = strtolower(trim($s));
        return $s;
    }

    // Resuelve el profesor asignado a una celda de horario para un override.
    // Primero busca en el horario del curso donde el alumno recursa la materia; si no encuentra,
    // busca en el horario base del curso del alumno.
    private function resolverProfesorParaOverride(int $alumnoId, int $dia, string $slotKey, ?int $materiaId, string $marcado, ?object $alumnoRow): array
    {
        $profId = null;
        $profNom = null;

        // 1) Recursando: buscar en el curso donde recursa esa materia
        if ($marcado === 'recursando' && $materiaId) {
            $recRows = DB::table('alumno_recursandos')
                ->where('alumno_id', $alumnoId)
                ->where('materia_id', $materiaId)
                ->get();

            foreach ($recRows as $rr) {
                $cursoRec = $this->findCurso((int)$rr->anio, strtoupper((string)$rr->division));
                if (!$cursoRec) continue;

                // a) mismo día+slot
                $hit = DB::table('horario_celdas as hc')
                    ->where('hc.curso_id', $cursoRec->id)
                    ->where('hc.dia', $dia)
                    ->where('hc.slot_key', $slotKey)
                    ->where('hc.materia_id', $materiaId)
                    ->select('hc.profesor_id')
                    ->first();

                if ($hit && $hit->profesor_id) {
                    $profId  = (int)$hit->profesor_id;
                    $profNom = $this->profesorNombreById($profId);
                    if ($profNom) return [$profId, $profNom];
                }

                // b) cualquier bloque de esa materia
                $hit2 = DB::table('horario_celdas as hc')
                    ->where('hc.curso_id', $cursoRec->id)
                    ->where('hc.materia_id', $materiaId)
                    ->whereNotNull('hc.profesor_id')
                    ->select('hc.profesor_id')
                    ->first();

                if ($hit2 && $hit2->profesor_id) {
                    $profId  = (int)$hit2->profesor_id;
                    $profNom = $this->profesorNombreById($profId);
                    if ($profNom) return [$profId, $profNom];
                }
            }
        }

        // 2) Caso normal: curso del alumno
        if ($alumnoRow) {
            [$anio, $div] = $this->splitCursoFromAlumno($alumnoRow);
            $curso = $this->findCurso($anio, $div);
            if ($curso) {
                // a) mismo día+slot con esa materia
                if ($materiaId) {
                    $hit = DB::table('horario_celdas as hc')
                        ->where('hc.curso_id', $curso->id)
                        ->where('hc.dia', $dia)
                        ->where('hc.slot_key', $slotKey)
                        ->where('hc.materia_id', $materiaId)
                        ->select('hc.profesor_id')
                        ->first();
                    if ($hit && $hit->profesor_id) {
                        $profId  = (int)$hit->profesor_id;
                        $profNom = $this->profesorNombreById($profId);
                        if ($profNom) return [$profId, $profNom];
                    }
                }

                // b) cualquier bloque de esa materia
                if ($materiaId) {
                    $hit2 = DB::table('horario_celdas as hc')
                        ->where('hc.curso_id', $curso->id)
                        ->where('hc.materia_id', $materiaId)
                        ->whereNotNull('hc.profesor_id')
                        ->select('hc.profesor_id')
                        ->first();
                    if ($hit2 && $hit2->profesor_id) {
                        $profId  = (int)$hit2->profesor_id;
                        $profNom = $this->profesorNombreById($profId);
                        if ($profNom) return [$profId, $profNom];
                    }
                }
            }
        }

        return [$profId, $profNom];
    }

    // Obtiene el horario completo del alumno combinando la base del curso, los overrides individuales,
    // y los overlays de materias recursadas o en intensificacion. Devuelve el horario efectivo resultante.
    /* ------------------------------ SHOW ------------------------------ */
    public function show(Request $req, int $alumnoId)
    {
        $alumno = DB::table('alumnos')->where('id', $alumnoId)->first();
        if (!$alumno) return response()->json(['message' => 'Alumno no encontrado'], 404);

        // Lectura por ámbito: profesor/preceptor solo ven alumnos de sus cursos; sin_rol recibe 403.
        $this->assertCanReadCurso($req, $alumno->anio ?? null, $alumno->division ?? null);

        [$anioAlumno, $divAlumno] = $this->splitCursoFromAlumno($alumno);
        $curso = $this->findCurso($anioAlumno, $divAlumno);

        // Obtiene el horario base del curso al que pertenece el alumno (considerando su grupo A/B).
        /* ---------- BASE ---------- */
        $base = [];
        $alumnoGrupo = $alumno->grupo ?? null;
        if ($curso) {
            $rows = DB::table('horario_celdas as hc')
                ->leftJoin('materias as m', 'm.id', '=', 'hc.materia_id')
                ->leftJoin('profesores as p', 'p.id', '=', 'hc.profesor_id')
                ->where('hc.curso_id', $curso->id)
                // El horario general del curso (grupo NULL) es la base; la fila del
                // grupo del alumno, si existe, pisa esa celda.
                ->where(function ($q) use ($alumnoGrupo) {
                    $q->whereNull('hc.grupo');
                    if ($alumnoGrupo) {
                        $q->orWhere('hc.grupo', $alumnoGrupo);
                    }
                })
                ->orderByRaw('hc.grupo is null desc')
                ->select([
                    'hc.dia',
                    'hc.slot_key',
                    'hc.materia_id',
                    'hc.profesor_id',
                    DB::raw('COALESCE(m.nombre, NULL) as materia_nombre'),
                    DB::raw("TRIM(CONCAT_WS(' ', p.nombre, p.apellido)) as profesor_nombre"),
                ])
                ->get();

            // Una celda por (dia, modulo). La query trae primero el horario general
            // (grupo NULL) y despues la del grupo del alumno, que es la que vale.
            $basePorCelda = [];
            foreach ($rows as $r) {
                $celda = [
                    'dia'         => (int)$r->dia,
                    'slot_key'    => $r->slot_key, // acepta h1..h8 (o más si existiera)
                    'materia_id'  => $r->materia_id ? (int)$r->materia_id : null,
                    'materia'     => $r->materia_nombre,
                    'profesor_id' => $r->profesor_id ? (int)$r->profesor_id : null,
                    'profesor'    => $r->profesor_nombre,
                    'origen'      => 'base',
                ];
                $basePorCelda[$r->dia . '|' . $r->slot_key] = $celda;
            }
            $base = array_values($basePorCelda);
        }

        // Obtiene los overrides individuales del alumno que modifican el horario base (cambios de materia o profesor).
        /* ---------- OVERRIDES ---------- */
        $overrides = DB::table('alumno_horario_overrides as a')
            ->leftJoin('materias as m', 'm.id', '=', 'a.materia_id')
            ->where('a.alumno_id', $alumnoId)
            ->select([
                'a.dia',
                'a.bloque',
                'a.materia_id',
                'a.profesor_id',
                'a.profesor_nombre',
                DB::raw('COALESCE(m.nombre, a.materia_nombre) as materia'),
                'a.marcado',
            ])
            ->get()
            ->map(function ($r) {
                return [
                    'dia'         => (int)$r->dia,
                    'slot_key'    => SlotKey::fromIdx((int)$r->bloque), // ahora mapea 1..8 → h1..h8
                    'materia_id'  => $r->materia_id ? (int)$r->materia_id : null,
                    'materia'     => $r->materia,
                    'profesor_id' => $r->profesor_id ? (int)$r->profesor_id : null,
                    'profesor'    => $r->profesor_nombre ?: null,
                    'marcado'     => $r->marcado ?? 'normal',
                    'origen'      => 'override',
                ];
            })
            ->all();

        // Obtiene los overlays de materias recursadas: busca los bloques horarios de esas materias en el curso donde se recursan.
        /* ---------- OVERLAYS (recursa / intensifica) ---------- */
        $overlayRec = [];
        $recRows = DB::table('alumno_recursandos')->where('alumno_id', $alumnoId)->get();
        // Nombres de materias en 1 sola query (evita 1 query por fila).
        $recMatIds = $recRows->pluck('materia_id')->filter()->unique()->values()->all();
        $recMatNames = $recMatIds
            ? DB::table('materias')->whereIn('id', $recMatIds)->pluck('nombre', 'id')
            : collect();
        // Cursos memoizados por par anio|division (misma query, sin repetirla).
        $cursoMemo = [];
        $findCursoMemo = function (?int $anio, ?string $division) use (&$cursoMemo) {
            $k = (string)$anio . '|' . (string)$division;
            if (!array_key_exists($k, $cursoMemo)) {
                $cursoMemo[$k] = $this->findCurso($anio, $division);
            }
            return $cursoMemo[$k];
        };
        foreach ($recRows as $row) {
            if (!$row->materia_id) continue;

            $cursoRec = $findCursoMemo((int)$row->anio, strtoupper((string)$row->division));
            if (!$cursoRec) continue;

            $slots = DB::table('horario_celdas as hc')
                ->leftJoin('profesores as p', 'p.id', '=', 'hc.profesor_id')
                ->where('hc.curso_id', $cursoRec->id)
                ->where('hc.materia_id', $row->materia_id)
                ->select([
                    'hc.dia',
                    'hc.slot_key',
                    'hc.profesor_id',
                    DB::raw("TRIM(CONCAT_WS(' ', p.nombre, p.apellido)) as profesor_nombre"),
                ])
                ->get();

            $matName = $recMatNames->get($row->materia_id);

            foreach ($slots as $s) {
                $overlayRec[] = [
                    'dia'         => (int)$s->dia,
                    'slot_key'    => $s->slot_key, // h1..h8
                    'materia_id'  => (int)$row->materia_id,
                    'materia'     => $matName,
                    'profesor_id' => $s->profesor_id ? (int)$s->profesor_id : null,
                    'profesor'    => $s->profesor_nombre,
                    'anio'        => (int)$cursoRec->anio,
                    'division'    => (string)$cursoRec->division,
                    'marcado'     => 'recursando',
                    'origen'      => 'overlay',
                ];
            }
        }

        $overlayInt = [];
        $intRows = DB::table('alumno_intensificaciones')->where('alumno_id', $alumnoId)->get();
        // Nombres de materias en 1 sola query (evita 1 query por fila).
        $intMatIds = $intRows->pluck('materia_id')->filter()->unique()->values()->all();
        $intMatNames = $intMatIds
            ? DB::table('materias')->whereIn('id', $intMatIds)->pluck('nombre', 'id')
            : collect();
        foreach ($intRows as $row) {
            if (!$row->materia_id) continue;

            $cursoInt = $findCursoMemo((int)$row->anio, strtoupper((string)$row->division));
            if (!$cursoInt) continue;

            $slots = DB::table('horario_celdas as hc')
                ->leftJoin('profesores as p', 'p.id', '=', 'hc.profesor_id')
                ->where('hc.curso_id', $cursoInt->id)
                ->where('hc.materia_id', $row->materia_id)
                ->select([
                    'hc.dia',
                    'hc.slot_key',
                    'hc.profesor_id',
                    DB::raw("TRIM(CONCAT_WS(' ', p.nombre, p.apellido)) as profesor_nombre"),
                ])
                ->get();

            $matName = $intMatNames->get($row->materia_id);

            foreach ($slots as $s) {
                $overlayInt[] = [
                    'dia'         => (int)$s->dia,
                    'slot_key'    => $s->slot_key,
                    'materia_id'  => (int)$row->materia_id,
                    'materia'     => $matName,
                    'profesor_id' => $s->profesor_id ? (int)$s->profesor_id : null,
                    'profesor'    => $s->profesor_nombre,
                    'anio'        => (int)$cursoInt->anio,
                    'division'    => (string)$cursoInt->division,
                    'marcado'     => 'intensificacion',
                    'origen'      => 'overlay',
                ];
            }
        }

        // Rellena automaticamente el profesor en los overrides que no lo tengan especificado,
        // buscando por coincidencia de materia en el horario base o en los overlays de recursada.
        /* ---------- Relleno de profesor en overrides si falta (post-proceso) ---------- */
        $baseByKey = [];
        $baseByMatId = [];
        $baseByMatNorm = [];

        foreach ($base as $b) {
            $k = $b['dia'].'|'.$b['slot_key'];
            $baseByKey[$k] = $b;
            if ($b['materia_id']) $baseByMatId[$b['materia_id']] = $b; // <-- FIX
            if ($b['materia'])    $baseByMatNorm[$this->norm($b['materia'])] = $b;
        }

        $recByKey = [];
        $recByMatId = [];
        $recByMatNorm = [];

        foreach ($overlayRec as $r) {
            $k = $r['dia'].'|'.$r['slot_key'];
            if (!isset($recByKey[$k])) $recByKey[$k] = [];
            $recByKey[$k][] = $r;
            if ($r['materia_id']) $recByMatId[$r['materia_id']] = $r;
            if ($r['materia'])    $recByMatNorm[$this->norm($r['materia'])] = $r;
        }

        foreach ($overrides as &$o) {
            $profEmpty = empty($o['profesor']) && empty($o['profesor_id']);
            if (!$profEmpty) continue;

            $key = $o['dia'].'|'.$o['slot_key'];

            if (($o['marcado'] ?? 'normal') === 'recursando') {
                if (!empty($recByKey[$key])) {
                    foreach ($recByKey[$key] as $cand) {
                        if ($this->sameMateria($o['materia_id'] ?? null, $cand['materia_id'] ?? null, $o['materia'] ?? null, $cand['materia'] ?? null)) {
                            $o['profesor_id'] = $cand['profesor_id'] ?? null;
                            $o['profesor']    = $cand['profesor']    ?? null;
                            break;
                        }
                    }
                }
                if (empty($o['profesor']) && empty($o['profesor_id'])) {
                    $cand = null;
                    if (!empty($o['materia_id']) && isset($recByMatId[$o['materia_id']])) $cand = $recByMatId[$o['materia_id']];
                    elseif (!empty($o['materia']) && isset($recByMatNorm[$this->norm($o['materia'])])) $cand = $recByMatNorm[$this->norm($o['materia'])];
                    if ($cand) {
                        $o['profesor_id'] = $cand['profesor_id'] ?? null;
                        $o['profesor']    = $cand['profesor']    ?? null;
                    }
                }
            } else {
                if (isset($baseByKey[$key])) {
                    $b = $baseByKey[$key];
                    if ($this->sameMateria($o['materia_id'] ?? null, $b['materia_id'] ?? null, $o['materia'] ?? null, $b['materia'] ?? null)) {
                        $o['profesor_id'] = $b['profesor_id'] ?? null;
                        $o['profesor']    = $b['profesor']    ?? null;
                    }
                }
                if (empty($o['profesor']) && empty($o['profesor_id'])) {
                    $b = null;
                    if (!empty($o['materia_id']) && isset($baseByMatId[$o['materia_id']])) $b = $baseByMatId[$o['materia_id']];
                    elseif (!empty($o['materia']) && isset($baseByMatNorm[$this->norm($o['materia'])])) $b = $baseByMatNorm[$this->norm($o['materia'])];
                    if ($b) {
                        $o['profesor_id'] = $b['profesor_id'] ?? null;
                        $o['profesor']    = $b['profesor']    ?? null;
                    }
                }
            }
        }
        unset($o);

        // Construye el horario efectivo combinando base, overrides y overlays.
        // Los overrides tienen prioridad sobre la base, y los overlays se agregan si no hay conflicto.
        // Detecta conflictos cuando dos materias distintas ocupan el mismo dia y bloque.
        /* ---------- EFFECTIVE ---------- */
        $byCell = [];
        $put = function (array $cell) use (&$byCell) {
            $k = $cell['dia'] . '|' . $cell['slot_key'];
            if (!isset($byCell[$k])) {
                $byCell[$k] = $cell;
                return;
            }
            $a = $byCell[$k];
            if (($a['materia_id'] ?? null) !== ($cell['materia_id'] ?? null) ||
                ($a['materia'] ?? '') !== ($cell['materia'] ?? '')) {
                $byCell[$k]['conflict'] = true;
            }
        };

        foreach ($base as $c) $put($c);
        foreach ($overrides as $c) { $byCell[$c['dia'].'|'.$c['slot_key']] = $c; }
        foreach ($overlayRec as $c) {
            $k = $c['dia'].'|'.$c['slot_key'];
            if (!isset($byCell[$k])) $byCell[$k] = $c; else $put($c);
        }

        $effective = array_values($byCell);
        usort($effective, function ($a, $b) {
            $d = ($a['dia'] <=> $b['dia']);
            if ($d !== 0) return $d;
            $ia = (int)preg_replace('/\D+/', '', $a['slot_key']);
            $ib = (int)preg_replace('/\D+/', '', $b['slot_key']);
            return $ia <=> $ib;
        });

        $out = [
            'base'                    => $base,
            'overrides'               => $overrides,
            'recursando_overlay'      => $overlayRec,
            'intensificacion_overlay' => $overlayInt,
            'effective'               => $effective,
        ];

        if (!$req->boolean('with_base')) {
            $out = $effective;
        }

        return response()->json($out);
    }

    // Actualiza los overrides de horario de un alumno. Cada entrada puede especificar materia, profesor
    // y marcado (normal, recursando, intensificacion). Resuelve automaticamente el profesor si no se provee.
    /* ------------------------------ UPDATE ------------------------------ */
    public function update(Request $req, int $alumnoId)
    {
        $entries = $req->input('entries', []);
        if (!is_array($entries)) {
            return response()->json(['message' => 'El campo entries debe ser un array'], 422);
        }

        $alumno = DB::table('alumnos')->where('id', $alumnoId)->first();
        if (!$alumno) {
            return response()->json(['message' => 'Alumno no encontrado'], 404);
        }
        $this->assertCanWriteAlumno($req, Alumno::findOrFail($alumnoId));

        $antes = $this->fotoOverrides($alumnoId);

        DB::transaction(function () use ($entries, $alumnoId, $alumno) {
            foreach ($entries as $e) {
                $dia = (int)($e['dia'] ?? 0);

                if (array_key_exists('bloque', $e) && $e['bloque'] !== null && $e['bloque'] !== '') {
                    $bloque = (int)$e['bloque']; // admite 1..8
                } else {
                    $slotKey = \App\Support\SlotKey::normalize($e['slot_key'] ?? null);
                    $bloque  = \App\Support\SlotKey::toIdx($slotKey); // h1..h8 → 1..8
                }

                if ($dia < 1 || $dia > 5 || $bloque === null) continue;

                $slotKey = \App\Support\SlotKey::fromIdx($bloque);

                $materiaRaw   = $e['materia']    ?? null;
                $materiaIdRaw = $e['materia_id'] ?? null;

                $exists = DB::table('alumno_horario_overrides')
                    ->where('alumno_id', $alumnoId)
                    ->where('dia', $dia)
                    ->where('bloque', $bloque)
                    ->first();

                if ($materiaRaw === null && $materiaIdRaw === null) {
                    $payload = [
                        'alumno_id'       => $alumnoId,
                        'dia'             => $dia,
                        'bloque'          => $bloque,
                        'materia_id'      => null,
                        'materia_nombre'  => null,
                        'profesor_id'     => null,
                        'profesor_nombre' => null,
                        'marcado'         => 'normal',
                        'updated_at'      => now(),
                    ];
                    if ($exists) {
                        DB::table('alumno_horario_overrides')->where('id', $exists->id)->update($payload);
                    } else {
                        $payload['created_at'] = now();
                        DB::table('alumno_horario_overrides')->insert($payload);
                    }
                    continue;
                }

                $materiaId      = null;
                $materiaNombre  = null;

                if ($materiaIdRaw) {
                    $materiaId     = (int)$materiaIdRaw;
                    $materiaNombre = DB::table('materias')->where('id', $materiaId)->value('nombre');
                } else {
                    $materiaNombre = trim((string)$materiaRaw);
                    if ($materiaNombre !== '') {
                        // Primero la materia del año del alumno (desempata homónimas
                        // por especialidad del curso); si no hay, se acepta cualquier
                        // año para no perder el dato que antes se guardaba.
                        $row = \App\Support\MateriaResolver::porNombre((int) $alumno->anio, $alumno->division, $materiaNombre)
                            ?? DB::table('materias')->whereRaw('LOWER(nombre)=LOWER(?)', [$materiaNombre])->orderBy('id')->first();
                        if ($row) { $materiaId = (int)$row->id; $materiaNombre = $row->nombre; }
                    }
                }

                $profId     = $e['profesor_id'] ?? null;
                $profNombre = $e['profesor']    ?? null;

                $marcado = $e['marcado'] ?? 'normal';
                if (!in_array($marcado, ['normal', 'recursando', 'intensificacion'], true)) $marcado = 'normal';

                if (!$profId || !$profNombre) {
                    [$autoId, $autoNom] = $this->resolverProfesorParaOverride(
                        $alumnoId, $dia, $slotKey, $materiaId, $marcado, $alumno
                    );
                    if (!$profId && $autoId)     $profId     = $autoId;
                    if (!$profNombre && $autoNom) $profNombre = $autoNom;
                }

                if ($profId && !$profNombre) {
                    $profNombre = $this->profesorNombreById($profId);
                }

                $payload = [
                    'alumno_id'       => $alumnoId,
                    'dia'             => $dia,
                    'bloque'          => $bloque,
                    'materia_id'      => $materiaId,
                    'materia_nombre'  => $materiaNombre,
                    'profesor_id'     => $profId,
                    'profesor_nombre' => $profNombre,
                    'marcado'         => $marcado,
                    'updated_at'      => now(),
                ];

                if ($exists) {
                    DB::table('alumno_horario_overrides')->where('id', $exists->id)->update($payload);
                } else {
                    $payload['created_at'] = now();
                    DB::table('alumno_horario_overrides')->insert($payload);
                }
            }
        });

        $despues = $this->fotoOverrides($alumnoId);
        if ($antes !== $despues) {
            ActivityLogger::log(
                $req,
                'Ajustó el horario de ' . $alumno->apellido . ', ' . $alumno->nombre,
                'alumno.horario',
                'Alumno',
                $alumnoId,
                $antes ?: null,
                $despues ?: null
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Foto legible de los overrides del alumno (dia-bloque => materia, profesor
     * y marcado). Sirve para detectar si el guardado cambió algo de verdad y
     * para que el historial muestre solo los horarios que se tocaron.
     */
    private function fotoOverrides(int $alumnoId): array
    {
        $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];

        $foto = [];
        $filas = DB::table('alumno_horario_overrides')
            ->where('alumno_id', $alumnoId)
            ->orderBy('dia')
            ->orderBy('bloque')
            ->get();

        foreach ($filas as $f) {
            $texto = $f->materia_nombre ?: 'sin materia';
            if ($f->profesor_nombre) $texto .= ' · ' . $f->profesor_nombre;
            if ($f->marcado && $f->marcado !== 'normal') $texto .= ' (' . $f->marcado . ')';
            $clave = ($dias[$f->dia] ?? ('Día ' . $f->dia)) . ' ' . $f->bloque . 'ª hora';
            $foto[$clave] = $texto;
        }

        return $foto;
    }
}

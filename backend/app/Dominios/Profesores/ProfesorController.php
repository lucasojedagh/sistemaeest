<?php

namespace App\Dominios\Profesores;

use App\Http\Controllers\Controller;

use App\Dominios\Cursos\Materia;
use App\Dominios\Profesores\Profesor;
use App\Dominios\Profesores\ProfesorAsignacion;
use App\Dominios\Profesores\ProfesorBaja;
use App\Dominios\Usuarios\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

use App\Support\ActivityLogger;
use App\Support\LikeSearch;
use App\Support\SchoolYear;

// Controlador para la gestion completa de profesores: CRUD, busqueda y mapeo.
// La baja es borrado definitivo completo (sin papelera): ficha, asignaciones,
// bajas, y referencias en horarios liberadas (profesor_id a NULL).
class ProfesorController extends Controller
{
    use \App\Support\AuthorizesScope;

    // Techo de coincidencias devueltas por /profesores/en-horarios: una búsqueda
    // de dos letras puede casar con medio cuerpo docente; la UI muestra las
    // primeras y avisa que el resto quedó truncado.
    private const EN_HORARIOS_MAX = 2000;

    private const DIAS_POR_NUM = [1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie'];

    /** Max de años dinámico (según materias activas, nunca menor a 6) */
    private function maxYear(): int
    {
        return \App\Support\SchoolYear::maxYear();
    }

    // ========================= LISTA (ACTIVOS) =========================
    // GET /api/profesores?q=&anio=&division=&materia=&rol=&estado=
    // Lista los profesores activos con filtros opcionales por texto, anio, division, materia, rol y estado.
    // Incluye las asignaciones de cada profesor.
    public function index(Request $req)
    {
        $q        = trim((string) $req->query('q', ''));
        $anioRaw  = $req->query('anio', null);
        $anio     = is_null($anioRaw) || $anioRaw === '' ? null : (int) $anioRaw;
        $division = $req->query('division');
        $materia  = trim((string) $req->query('materia', '')); // nombre visible
        $rol      = $req->query('rol');      // titular|suplente|todos
        $estado   = $req->query('estado');   // Activo|Licencia|todos

        $query = Profesor::query()->with(['asignaciones.materia']);

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('nombre', 'like', LikeSearch::contains($q))
                   ->orWhere('apellido', 'like', LikeSearch::contains($q))
                   ->orWhere('email', 'like', LikeSearch::contains($q));
            });
        }

        if ($estado && $estado !== 'todos') {
            $query->where('estado', $estado);
        }

        if ($rol && $rol !== 'todos') {
            $query->where('suplente', $rol === 'suplente');
        }

        if (!is_null($anio) || $division || $materia) {
            $query->whereHas('asignaciones', function ($qa) use ($anio, $division, $materia) {
                if (!is_null($anio))  $qa->where('anio', (int) $anio);
                if ($division)        $qa->where('division', strtoupper($division));
                if ($materia) {
                    $qa->whereHas('materia', function ($qm) use ($materia) {
                        $qm->where('nombre', $materia)->where('activo', true);
                    });
                }
            });
        }

        // Paginar. DNI: prioridad a profesores.dni, fallback a users por email (batch).
        // Rangos bajos no reciben DNI (ni siquiera vía fallback): solo contacto y cursos.
        $full = $this->userCanSeeFullFicha($req);
        $profesores = $query->orderBy('apellido')->orderBy('nombre')
            ->paginate(\App\Support\Paginacion::porPagina($req, 100, 2000));

        // Recolectar emails de filas sin DNI para resolver en un solo query
        $nullDniEmails = [];
        if ($full) {
            foreach ($profesores->items() as $p) {
                if (!$p->dni && $p->email) {
                    $nullDniEmails[] = strtolower(trim($p->email));
                }
            }
        }

        $fallbackMap = [];
        if ($nullDniEmails) {
            $nullDniEmails = array_unique($nullDniEmails);
            $fallbackMap = \App\Dominios\Usuarios\User::whereIn('email', $nullDniEmails)
                ->pluck('dni', 'email')
                ->mapWithKeys(fn ($v, $k) => [strtolower(trim($k)) => $v])
                ->all();
        }

        // Etiquetas de suplencia (quién cubre a quién) en una sola consulta.
        $resumenSupl = \App\Support\Suplencias::resumenPara(
            collect($profesores->items())->map(fn (Profesor $p) => $p->id)->all()
        );
        // Materias cubiertas como suplente, con detalle (para etiquetas).
        $materiasSuplidas = \App\Support\Suplencias::materiasCubiertasPara(
            collect($profesores->items())->map(fn (Profesor $p) => $p->id)->all()
        );

        $profesores->through(function (Profesor $p) use ($fallbackMap, $req, $full, $resumenSupl, $materiasSuplidas) {
            $asigs = $p->asignaciones->map(function (ProfesorAsignacion $a) {
                $curso = $a->anio . '°' . ($a->division ? ' ' . $a->division : '');
                return [
                    'curso'      => $curso,
                    'materia'    => $a->materia?->nombre ?? '',
                    'materia_id' => $a->materia_id ? (int) $a->materia_id : null,
                    'anio'       => $a->anio,
                    'division'   => $a->division,
                    'grupo'      => $a->grupo,
                ];
            })->values();

            $dni = null;
            if ($full) {
                $dni = $p->dni;
                if (!$dni && $p->email) {
                    $key = strtolower(trim($p->email));
                    $dni = $fallbackMap[$key] ?? null;
                }
            }

            return [
                'id'            => $p->id,
                'nombre'        => $p->nombre,
                'apellido'      => $p->apellido,
                'email'         => $p->email,
                'dni'           => $dni,
                'creado_en'     => $full ? $p->created_at?->toDateTimeString() : null,
                'user_id'       => null,
                'estado'        => $p->estado,
                'suplente'      => (bool) $p->suplente,
                'observaciones' => ($full && $this->canSeeObservaciones($req, $p->email)) ? $p->observaciones : null,
                'asignaciones'  => $asigs,
                // Etiquetas: a quiénes cubre como suplente y por quiénes es cubierto.
                'suplente_de'   => $resumenSupl['recibe'][$p->id] ?? [],
                'cubierto_por'  => $resumenSupl['otorga'][$p->id] ?? [],
                // Materias que cubre como suplente (con titular y curso).
                'suplencias_materias' => $materiasSuplidas[$p->id] ?? [],
            ];
        });

        return response()->json($profesores);
    }

    // ========================= SHOW =========================
    // GET /api/profesores/{profesor}
    // Muestra los datos completos de un profesor junto con sus asignaciones.
    public function show(Request $req, Profesor $profesor)
    {
        $profesor->load('asignaciones.materia');

        // Rangos bajos: solo contacto (nombre/email) y cursos asignados.
        if (!$this->userCanSeeFullFicha($req)) {
            $resumenSupl = \App\Support\Suplencias::resumenPara([$profesor->id]);
            $materiasSuplidas = \App\Support\Suplencias::materiasCubiertasPara([$profesor->id]);
            return response()->json([
                'id'       => $profesor->id,
                'nombre'   => $profesor->nombre,
                'apellido' => $profesor->apellido,
                'email'    => $profesor->email,
                'asignaciones' => $profesor->asignaciones->map(function (ProfesorAsignacion $a) {
                    return [
                        'anio'     => $a->anio,
                        'division' => $a->division,
                        'grupo'    => $a->grupo,
                        'materia'  => $a->materia?->nombre ?? '',
                    ];
                })->values(),
                'suplente_de'   => $resumenSupl['recibe'][$profesor->id] ?? [],
                'cubierto_por'  => $resumenSupl['otorga'][$profesor->id] ?? [],
                'suplencias_materias' => $materiasSuplidas[$profesor->id] ?? [],
            ]);
        }

        $resumenSupl = \App\Support\Suplencias::resumenPara([$profesor->id]);
        $materiasSuplidas = \App\Support\Suplencias::materiasCubiertasPara([$profesor->id]);

        return response()->json([
            'id'            => $profesor->id,
            'nombre'        => $profesor->nombre,
            'apellido'      => $profesor->apellido,
            'email'         => $profesor->email,
            'dni'           => $profesor->dni ?? $this->dniFromUser($profesor->email),
            'user_id'       => null,
            'estado'        => $profesor->estado,
            'suplente'      => (bool) $profesor->suplente,
            'observaciones' => $this->canSeeObservaciones($req, $profesor->email) ? $profesor->observaciones : null,
            'suplente_de'   => $resumenSupl['recibe'][$profesor->id] ?? [],
            'cubierto_por'  => $resumenSupl['otorga'][$profesor->id] ?? [],
            'suplencias_materias' => $materiasSuplidas[$profesor->id] ?? [],
            'asignaciones'  => $profesor->asignaciones->map(function (ProfesorAsignacion $a) {
                return [
                    'anio'     => $a->anio,
                    'division' => $a->division,
                    'grupo'    => $a->grupo,
                    'materia'  => $a->materia?->nombre ?? '',
                ];
            })->values(),
        ]);
    }

    // ========================= STORE =========================
    // POST /api/profesores
    // Crea un nuevo profesor con sus asignaciones en una transaccion.
    public function store(Request $req)
    {
        $data = $this->validatePayload($req);

        return DB::transaction(function () use ($data, $req) {
            $prof = Profesor::create([
                'nombre'        => $data['nombre'],
                'apellido'      => $data['apellido'],
                'email'         => $data['email'] ?? null,
                'dni'           => $data['dni'] ?? null,
                'estado'        => $data['estado'] ?? 'Activo',
                'suplente'      => (bool) ($data['suplente'] ?? false),
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            $this->upsertAsignaciones($prof, $data['asignaciones']);

            ActivityLogger::log($req, 'Creó profesor ' . $prof->apellido . ', ' . $prof->nombre, 'profesor.store', 'Profesor', $prof->id);

            return $this->show($req, $prof);
        });
    }

    // ========================= STORE FROM USERS =========================
    // POST /api/profesores/from-users
    // Crea registros de profesor a partir de usuarios ya registrados en el sistema.
    // Recibe { ids: [...] }. Omite los que ya tienen un profesor con el mismo email.
    public function storeFromUsers(Request $req)
    {
        $ids = $req->input('ids');
        if (!is_array($ids)) {
            $ids = $ids ? [$ids] : [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));

        $users = User::whereIn('id', $ids)->get();
        $created = 0;
        $skipped = 0;

        foreach ($users as $u) {
            if (!$u->email) { $skipped++; continue; }
            if (Profesor::whereRaw('LOWER(TRIM(email)) = LOWER(TRIM(?))', [$u->email])->exists()) {
                $skipped++;
                continue;
            }
            Profesor::create([
                'nombre'        => $u->name ?? '',
                'apellido'      => $u->apellido ?? '',
                'email'         => $u->email,
                'dni'           => $u->dni ?? null,
                'estado'        => 'Activo',
                'suplente'      => false,
                'observaciones' => null,
            ]);
            $created++;
        }

        ActivityLogger::log(
            $req,
            'Añadió ' . $created . ' usuario(s) como profesor(es) desde la gestión de usuarios',
            'profesor.storeFromUsers',
            'Profesor',
            null
        );

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Mapa legible de las asignaciones del profesor, para el historial.
     * El diff centralizado muestra solo los cursos/materias que cambiaron.
     */
    private function mapaAsignaciones(Profesor $profesor): array
    {
        // OJO: `materia_nombre` es un accessor (viene de la relación `materia`),
        // no una columna de `profesor_asignaciones`: no se puede incluir en el
        // SELECT o MySQL devuelve 1054. Se cargan las columnas reales + relación.
        return $profesor->asignaciones()->with('materia')->get(['id', 'profesor_id', 'anio', 'division', 'grupo', 'materia_id'])
            ->map(fn ($a) => [
                'anio'           => (int) $a->anio,
                'division'       => strtoupper(trim((string) $a->division)),
                'materia_id'     => (int) $a->materia_id,
                'materia_nombre' => $a->materia_nombre,
            ])->values()->all();
    }

    // ========================= UPDATE =========================
    // PUT /api/profesores/{profesor}
    // Actualiza los datos de un profesor y reemplaza todas sus asignaciones en una transaccion.
    public function update(Request $req, Profesor $profesor)
    {
        $data = $this->validatePayload($req, $profesor->id);

        return DB::transaction(function () use ($profesor, $data, $req) {
            $oldValues = [
                'nombre'        => $profesor->nombre,
                'apellido'      => $profesor->apellido,
                'email'         => $profesor->email,
                'dni'           => $profesor->dni,
                'estado'        => $profesor->estado,
                'suplente'      => $profesor->suplente,
                'observaciones' => $profesor->observaciones,
            ];
            $oldAsignaciones = $this->mapaAsignaciones($profesor);

            $profesor->update([
                'nombre'        => $data['nombre'],
                'apellido'      => $data['apellido'],
                'email'         => $data['email'] ?? null,
                'dni'           => $data['dni'] ?? null,
                'estado'        => $data['estado'] ?? 'Activo',
                'suplente'      => (bool) ($data['suplente'] ?? false),
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            // Reemplazo total de asignaciones
            $profesor->asignaciones()->delete();

            $this->upsertAsignaciones($profesor, $data['asignaciones']);

            // Se manda la foto completa: el diff centralizado deja solo lo que cambió.
            ActivityLogger::log(
                $req,
                'Actualizó profesor ' . $profesor->apellido . ', ' . $profesor->nombre,
                'profesor.update',
                'Profesor',
                $profesor->id,
                $oldValues + ['asignaciones' => $oldAsignaciones],
                [
                    'nombre'        => $profesor->nombre,
                    'apellido'      => $profesor->apellido,
                    'email'         => $profesor->email,
                    'dni'           => $profesor->dni,
                    'estado'        => $profesor->estado,
                    'suplente'      => $profesor->suplente,
                    'observaciones' => $profesor->observaciones,
                    'asignaciones'  => $this->mapaAsignaciones($profesor),
                ]
            );

            return $this->show($req, $profesor);
        });
    }

    // ========================= FORCE DELETE =========================
    // DELETE /api/profesores/{id}/force
    // Borrado definitivo COMPLETO: ficha, asignaciones, bajas, y referencias
    // en horarios/overrides liberadas (profesor_id a NULL, se conserva materia
    // y grilla). Acepta profesores activos o en papelera (transición).
    public function forceDelete(Request $req, $id)
    {
        // Acción destructiva: exige confirmación de contraseña reciente.
        $this->assertPasswordConfirmed($req);

        $prof = Profesor::findOrFail($id);

        DB::transaction(function () use ($prof, $id, $req) {
            // Liberar celdas de horarios (sin FK: hay que nullificar a mano).
            DB::table('horario_celdas')->where('profesor_id', $id)->update(['profesor_id' => null]);
            DB::table('alumno_horario_overrides')->where('profesor_id', $id)->update(['profesor_id' => null]);

            // Borrar asignaciones, bajas y eslabones de suplencia.
            $prof->asignaciones()->delete();
            \App\Dominios\Profesores\ProfesorSuplencia::where('titular_id', $id)
                ->orWhere('suplente_id', $id)
                ->delete();
            if (Schema::hasTable('profesor_bajas')) {
                DB::table('profesor_bajas')->where('profesor_id', $id)->delete();
            }

            $prof->delete();

            ActivityLogger::log($req, 'Eliminó definitivamente profesor ' . $prof->apellido . ', ' . $prof->nombre, 'profesor.forceDelete', 'Profesor', $id);
        });

        return response()->json(['ok' => true, 'message' => 'Profesor eliminado definitivamente']);
    }

    // ========================= MAPPING =========================
    // GET /api/profesores/mapping?anio=1&division=A&estado=Activo
    // Genera un mapa de profesores asignados por materia para un anio (y division opcional),
    // util para los selectores del frontend.
    public function mapping(Request $req)
    {
        $anio = (int) $req->query('anio', 0);
        $maxYear = $this->maxYear();
        if ($anio < 1 || $anio > $maxYear) {
            return response()->json(['error' => "anio requerido (1..{$maxYear})"], 422);
        }

        $division = $req->query('division'); // opcional
        $estado   = $req->query('estado', 'Activo');

        $asigs = ProfesorAsignacion::query()
            ->with(['profesor','materia'])
            ->where('anio', $anio)
            ->when($division, fn($q) => $q->where('division', strtoupper($division)))
            ->whereHas('materia', fn($q) => $q->where('activo', true))
            ->when($estado !== 'todos', function ($q) use ($estado) {
                $q->whereHas('profesor', fn($qp) => $qp->where('estado', $estado));
            })
            ->get();

        $map = [];
        foreach ($asigs as $a) {
            $mat = $a->materia?->nombre ?? '';
            if ($mat === '') continue;
            // Asignación huérfana (profesor borrado): se salta en vez de romper con 500.
            $prof = $a->profesor;
            if (!$prof) continue;
            $map[$mat] ??= [];
            $map[$mat][] = [
                'id'       => $prof->id,
                'nombre'   => $prof->nombre.' '.$prof->apellido,
                'suplente' => (bool) $prof->suplente,
                'estado'   => $prof->estado,
            ];
        }
        $collator = null;
        if (class_exists(\Collator::class)) {
            $collator = new \Collator('es_ES');
            $collator->setAttribute(\Collator::CASE_FIRST, \Collator::OFF);
        }
        foreach ($map as &$lista) {
            if ($collator) {
                usort($lista, fn($a, $b) => $collator->compare($a['nombre'], $b['nombre']));
            } else {
                setlocale(LC_COLLATE, 'es_ES.UTF-8', 'Spanish_Spain.1252', 'es_AR.UTF-8');
                usort($lista, fn($a, $b) => strcoll($a['nombre'], $b['nombre']));
            }
        }

        return response()->json($map);
    }

    // GET /api/profesores/en-horarios?q=<nombre o id>&anios=1,2
    // Devuelve las celdas de horario en las que aparece ese docente. El modal
    // "Buscar profesor" escaneaba los 63 cursos uno por uno (2 requests y una
    // pausa de 420 ms por curso, ~3 minutos); esto resuelve lo mismo en una
    // consulta, sin importar cuántos cursos haya.
    public function enHorarios(Request $req)
    {
        $q = trim((string) $req->query('q', ''));
        if ($q === '') {
            return response()->json(['error' => 'Falta el término de búsqueda.'], 422);
        }
        $esId = ctype_digit($q);
        if (!$esId && mb_strlen($q) < 2) {
            return response()->json(['error' => 'Ingresá al menos 2 letras o un ID.'], 422);
        }

        $cursoIds = $this->cursoIdsVisibles($req);
        if (is_array($cursoIds) && count($cursoIds) === 0) {
            return response()->json([
                'resultados' => [], 'cursos_escaneados' => 0, 'total_coincidencias' => 0, 'truncado' => false,
            ]);
        }

        $anios = collect(explode(',', (string) $req->query('anios', '')))
            ->map(fn ($s) => (int) trim($s))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $base = DB::table('horario_celdas as hc')
            ->join('cursos as c', 'c.id', '=', 'hc.curso_id')
            ->join('profesores as p', 'p.id', '=', 'hc.profesor_id')
            ->leftJoin('materias as m', 'm.id', '=', 'hc.materia_id')
            ->whereNull('p.deleted_at')
            // igual que la grilla del curso: sin grupo = horario común
            ->whereNull('hc.grupo')
            ->when($cursoIds !== null, fn ($qq) => $qq->whereIn('hc.curso_id', $cursoIds))
            ->when($anios, fn ($qq) => $qq->whereIn('c.anio', $anios))
            ->when($esId, fn ($qq) => $qq->where('p.id', (int) $q))
            ->when(!$esId, function ($qq) use ($q) {
                $like = LikeSearch::contains($q);
                // El colatín de MySQL ya es insensible a tildes, así que alcanza
                // con las dos órdenes posibles del nombre.
                $qq->where(function ($w) use ($like) {
                    $w->whereRaw("CONCAT(p.nombre, ' ', p.apellido) LIKE ?", [$like])
                      ->orWhereRaw("CONCAT(p.apellido, ' ', p.nombre) LIKE ?", [$like]);
                });
            });

        $total    = (clone $base)->count();
        $cursos   = (clone $base)->distinct()->count('hc.curso_id');
        $max      = self::EN_HORARIOS_MAX;
        $filaList = $base
            ->orderBy('c.anio')
            ->orderBy('c.division')
            ->orderByRaw("CAST(SUBSTRING(hc.slot_key, 2) AS UNSIGNED)")
            ->orderBy('hc.dia')
            ->limit($max)
            ->get([
                'c.anio', 'c.division', 'hc.dia', 'hc.slot_key', 'hc.aula',
                'm.nombre as materia', 'p.id as profesor_id',
                'p.nombre as profesor_nombre', 'p.apellido as profesor_apellido',
                'p.estado as profesor_estado', 'p.suplente as profesor_suplente',
            ])
            ->all();

        $resultados = array_map(static fn ($f) => [
            'anio'            => (int) $f->anio,
            'division'        => (string) $f->division,
            'dia'             => (int) $f->dia,
            'dia_key'         => self::DIAS_POR_NUM[(int) $f->dia] ?? 'Lun',
            'slot_key'        => (string) $f->slot_key,
            'materiaNombre'   => (string) ($f->materia ?? ''),
            'aula'            => $f->aula ? (string) $f->aula : null,
            'profesorId'      => (int) $f->profesor_id,
            'profesorNombre'  => trim($f->profesor_nombre . ' ' . $f->profesor_apellido),
            'profesorEstado'  => (string) $f->profesor_estado,
            'suplente'        => (bool) $f->profesor_suplente,
        ], $filaList);

        return response()->json([
            'resultados'          => $resultados,
            'cursos_escaneados'   => $cursos,
            'total_coincidencias' => $total,
            'truncado'            => $total > count($resultados),
        ]);
    }

    // Cursos que el usuario puede ver. null = todos (roles de gestión global).
    // Replica el criterio de GET /horarios/{anio}/{division}, que era el que
    // filtraba durante el escaneo curso por curso.
    private function cursoIdsVisibles(Request $req): ?array
    {
        if ($this->userCanWriteAll($req)) return null;
        if (!$this->userCanReadAll($req)) {
            abort(403, 'No tenés permiso para consultar horarios.');
        }

        $pairs = $this->assignedPairs($req);
        if (!$pairs) return [];

        $ids = [];
        foreach ($pairs as $p) {
            $anio = (int) $p['anio'];
            if ($anio < 1) continue;
            $ids = array_merge($ids, \App\Dominios\Cursos\Curso::where('anio', $anio)
                ->when(!empty($p['division']), fn ($q) => $q->where('division', strtoupper((string) $p['division'])))
                ->pluck('id')
                ->all());
        }
        return array_values(array_unique($ids));
    }

    // ========================= HELPERS =========================
    // Devuelve el DNI del usuario vinculado al profesor (por email), o null.
    // Las observaciones de un profesor solo las ven los roles superiores y el propio
    // profesor (mismo email). Un profesor no ve las observaciones de sus pares.
    private function canSeeObservaciones(Request $req, ?string $profesorEmail): bool
    {
        $u = $req->user();
        if (!$u) return false;
        if ($u->role !== 'profesor') return true;
        return $profesorEmail !== null
            && strtolower(trim($profesorEmail)) === strtolower(trim((string) $u->email));
    }

    private function dniFromUser(?string $email): ?string
    {
        if (!$email) return null;
        $user = \App\Dominios\Usuarios\User::where('email', $email)->first();
        return $user?->dni ?? null;
    }

    private function validatePayload(Request $req, ?int $ignoreId = null): array
    {
        $maxYear = $this->maxYear();

        return $req->validate([
            'nombre'   => ['required','string','max:120'],
            'apellido' => ['required','string','max:120'],
            'email'    => ['nullable','email','max:160', Rule::unique('profesores','email')->ignore($ignoreId)],
            'dni'      => ['nullable','string','max:20'],
            'estado'   => ['nullable', Rule::in(['Activo','Licencia'])],
            'suplente' => ['nullable','boolean'],
            'observaciones' => ['nullable','string'],

            'asignaciones'            => ['required','array'],
            'asignaciones.*.anio'     => ['required','integer','min:1','max:'.$maxYear],
            'asignaciones.*.division' => ['nullable','string','max:1'],
            'asignaciones.*.grupo'    => ['nullable','string','size:1', Rule::in(['A','B'])],
            'asignaciones.*.materia'  => ['required','string','max:120'],
        ]);
    }

    private function upsertAsignaciones(Profesor $prof, array $input): void
    {
        foreach ($input as $a) {
            $anio = (int) $a['anio'];
            $division = !empty($a['division']) ? strtoupper($a['division']) : null;
            $nombreMateria = trim($a['materia']);

            $mat = Materia::where('anio', $anio)
                          ->where('nombre', $nombreMateria)
                          ->where('activo', true)
                          ->first();

            if (!$mat) {
                abort(422, "La materia '{$nombreMateria}' no existe para {$anio}° (o no está activa).");
            }

            $grupo = !empty($a['grupo']) ? strtoupper($a['grupo']) : null;

            $prof->asignaciones()->create([
                'anio'       => $anio,
                'division'   => $division,
                'grupo'      => $grupo,
                'materia_id' => $mat->id,
            ]);
        }
    }
}

<?php

namespace App\Dominios\Alumnos;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Materia;
use App\Dominios\Cursos\ParametroDivision;
use App\Support\CourseLabel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\ActivityLogger;
use App\Support\Descarga;
use App\Support\LikeSearch;
use App\Support\PadronExcel;
use App\Support\SchoolYear;

// Controlador para la gestion de alumnos.
// Proporciona endpoints para listar, crear, actualizar, eliminar y egresar alumnos,
// incluyendo la administracion de datos personales, direcciones, telefonos, responsables,
// materias recursadas e intensificaciones.
class AlumnoController extends Controller
{
    use \App\Support\AuthorizesScope;
    /* ======================= Helpers ======================= */

// Construye un mapa de anios activos con sus divisiones desde la tabla parametro_division.
    // Si no hay configuracion, devuelve un mapa por defecto con anios 1..maxYear y divisiones 1 a 9.
    // El tope centraliza en SchoolYear::maxYear() para no divergir del resto del sistema.
    /** [anio => ['1','2',...]]; fallback 1..maxYear si no hay config */
    private function activeYearsMap(): array
    {
        $rows = ParametroDivision::where('activo', true)
            ->orderBy('anio')->orderBy('division')
            ->get(['anio', 'division']);

        if ($rows->isEmpty()) {
            $map = [];
            foreach (range(1, \App\Support\SchoolYear::maxYear()) as $i) {
                $map[$i] = ['1','2','3','4','5','6','7','8','9'];
            }
            return $map;
        }

        $map = [];
        foreach ($rows as $r) {
            $a = (int) $r->anio;
            $d = strtoupper(trim($r->division));
            if ($d === '') continue;
            if (!isset($map[$a])) $map[$a] = [];
            if (!in_array($d, $map[$a], true)) $map[$a][] = $d;
        }
        ksort($map);
        return $map;
    }

// Obtiene el anio maximo activo desde la configuracion; utiliza el fallback de SchoolYear si no hay datos.
    /** Tope dinámico: mayor año activo (o fallback 7 si no hay nada) */
    private function dynamicMaxYear(): int
    {
        return \App\Support\SchoolYear::maxYear();
    }

    /** Divisiones válidas para un año (o [] si no hay) */
    private function validDivisionsForYear(int $anio): array
    {
        $map = $this->activeYearsMap();
        return $map[$anio] ?? [];
    }

    /** Normaliza división a una sola letra mayúscula o null */
    private function normalizeDivision($val): ?string
    {
        $d = strtoupper(trim((string)$val));
        $d = preg_replace('/[^A-Z0-9]/', '', $d);
        if ($d === '') return null;
        return substr($d, 0, 1);
    }

    /** Materia por (anio,nombre); la division desempata homónimas de otra especialidad */
    private function findMateriaId(int $anio, string $nombre, ?string $division = null): int
    {
        $m = \App\Support\MateriaResolver::porNombre($anio, $division, $nombre);

        if (!$m || !$m->activo) {
            abort(422, 'La materia "' . $nombre . '" no existe para ' . $anio . '° (o no está activa).');
        }
        return $m->id;
    }

    /** Quita duplicados por (anio,division,materia_id) */
    private function dedupeRecInt(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $r) {
            $a  = (int)($r['anio'] ?? 0);
            $d  = $this->normalizeDivision($r['division'] ?? null);
            $mi = (int)($r['materia_id'] ?? 0);
            $k  = "{$a}|{$d}|{$mi}";
            if ($a > 0 && $mi > 0 && !isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = [
                    'anio'           => $a,
                    'division'       => $d,
                    'materia_id'     => $mi,
                    'materia_nombre' => trim((string)($r['materia_nombre'] ?? ($r['materia'] ?? ''))),
                ];
            }
        }
        return $out;
    }

    /* ============================= Endpoints ============================= */

    // Lista los alumnos con filtros opcionales por busqueda (q), anio, division y genero.
    // Incluye relaciones de direcciones, telefonos, recursadas e intensificaciones.
    // GET /api/alumnos?q=&anio=&division=&genero=
    public function index(Request $req)
    {
        $q        = trim((string) $req->query('q', ''));
        $anio     = $req->integer('anio');
        $division = $req->query('division');

        $generoRaw = (string) $req->query('genero', '');
        $gUp = strtoupper($generoRaw);
        $mapG = ['FEMENINO' => 'F', 'MASCULINO' => 'M', 'OTROS' => 'O'];
        $genero = $mapG[$gUp] ?? $gUp; // '', F, M, O, TODOS

        $query = Alumno::query()
            ->with(['direcciones', 'telefonos', 'responsables', 'recursandos.materia', 'intensificaciones.materia'])
            ->withCount(['recursandos as rec_count', 'intensificaciones as int_count']);

        // Lectura por ámbito: profesor/preceptor solo ven sus cursos; sin_rol recibe 403.
        $this->scopeAlumnoIndex($req, $query);

        if ($q !== '') {
            $like = LikeSearch::contains($q);
            $query->where(function ($qq) use ($like) {
                $qq->where('nombre', 'like', $like)
                   ->orWhere('apellido', 'like', $like)
                   ->orWhere('dni', 'like', $like)
                   ->orWhere('matricula', 'like', $like);
            });
        }

        if ($genero && $genero !== 'TODOS') $query->where('genero', $genero);
        if ($anio)                         $query->where('anio', $anio);
        if ($division)                     $query->where('division', strtoupper($division));

        $rows = $query->orderBy('apellido')->orderBy('nombre')
            ->paginate(\App\Support\Paginacion::porPagina($req, 100, 2000))
            ->through(function (Alumno $a) {
            $anioLabel = \App\Support\CourseLabel::label($a->anio, $a->division);
            return [
                'id'               => $a->id,
                'nombre'           => $a->nombre,
                'apellido'         => $a->apellido,
                'genero'           => $a->genero,
                'nacionalidad'     => $a->nacionalidad,
                'dni'              => $a->dni,
                'matricula'        => $a->matricula,
                'fecha_nacimiento' => $a->fecha_nacimiento?->format('Y-m-d'),
                'creado_en'        => $a->created_at?->toDateTimeString(),
                // Version de concurrencia: el formulario la devuelve en _version
                // al guardar y el servidor responde 409 si alguien editó antes.
                'version'          => $a->updated_at?->getTimestamp(),
                'anio'             => $anioLabel,
                'anio_num'         => $a->anio,
                'division'         => $a->division,
                'grupo'            => $a->grupo,
                'estado'           => $a->estado,

                'especialidad'       => $a->especialidad,
                'especialidad_label' => $a->especialidad_label,

                'tiene_ppi'        => (bool) $a->tiene_ppi,
                'tiene_ae'         => (bool) $a->tiene_ae,

                'egreso_year'      => $a->egreso_year,
                'egreso_anio'      => $a->egreso_anio,
                'egreso_division'  => $a->egreso_division,
                'observaciones'    => $a->observaciones,

                'rec_count'        => (int) ($a->rec_count ?? 0),
                'int_count'        => (int) ($a->int_count ?? 0),

                'direcciones'      => $a->direcciones->map(fn($d) => [
                    'titular' => $d->titular,
                    'direccion' => $d->direccion,
                ])->values(),
                'telefonos'        => $a->telefonos->map(fn($t) => [
                    'titular' => $t->titular,
                    'telefono' => $t->telefono,
                ])->values(),
                'responsables'     => $a->responsables->map(fn($r) => [
                    'nombre'      => $r->nombre,
                    'apellido'    => $r->apellido,
                    'parentesco'  => $r->parentesco,
                    'convive'     => (bool) $r->convive,
                    'tutor_legal' => (bool) $r->tutor_legal,
                ])->values(),
                'recursando'       => $a->recursandos->map(fn($r) => [
                'anio'     => $r->anio ? \App\Support\CourseLabel::anio($r->anio) : null,
                    'division' => $r->division,
                    'materia'  => $r->materia?->nombre ?? '',
                ])->values(),
                'intensificacion'  => $a->intensificaciones->map(fn($r) => [
                    'anio'     => $r->anio ? \App\Support\CourseLabel::anio($r->anio) : null,
                    'division' => $r->division,
                    'materia'  => $r->materia?->nombre ?? '',
                ])->values(),
            ];
        });

        return response()->json($rows);
    }

    // Obtiene los datos completos de un alumno especifico, incluyendo relaciones, recursadas e intensificaciones.
    // GET /api/alumnos/{alumno}
    public function show(Request $req, Alumno $alumno)
    {
        // Lectura por ámbito: profesor/preceptor solo ven alumnos de sus cursos; sin_rol recibe 403.
        $this->assertCanReadCurso($req, $alumno->anio, $alumno->division);

        $alumno->load([
                'direcciones',
                'telefonos',
                'recursandos.materia',
                'intensificaciones.materia',
                'responsables',
            ])
            ->loadCount(['recursandos as rec_count', 'intensificaciones as int_count']);

        $anioLabel = $alumno->anio ? ($alumno->anio . '°' . ($alumno->division ? ' ' . $alumno->division : '')) : '';

        return response()->json([
            'id'               => $alumno->id,
            'nombre'           => $alumno->nombre,
            'apellido'         => $alumno->apellido,
            'genero'           => $alumno->genero,
            'nacionalidad'     => $alumno->nacionalidad,
            'dni'              => $alumno->dni,
            'matricula'        => $alumno->matricula,
            'fecha_nacimiento' => $alumno->fecha_nacimiento?->format('Y-m-d'),
            'anio'             => $anioLabel,
            'anio_num'         => $alumno->anio,
            'division'         => $alumno->division,
            'grupo'            => $alumno->grupo,
            'estado'           => $alumno->estado,

            'especialidad'       => $alumno->especialidad,
            'especialidad_label' => $alumno->especialidad_label,

            'tiene_ppi'        => (bool) $alumno->tiene_ppi,
            'tiene_ae'         => (bool) $alumno->tiene_ae,

            'egreso_year'      => $alumno->egreso_year,
            'egreso_anio'      => $alumno->egreso_anio,
            'egreso_division'  => $alumno->egreso_division,
            'observaciones'    => $alumno->observaciones,
            'version'          => $alumno->updated_at?->getTimestamp(),

            'rec_count'        => (int) ($alumno->rec_count ?? 0),
            'int_count'        => (int) ($alumno->int_count ?? 0),

            'direcciones'      => $alumno->direcciones->map(fn($d) => [
                'titular'   => $d->titular,
                'direccion' => $d->direccion,
            ])->values(),
            'telefonos'        => $alumno->telefonos->map(fn($t) => [
                'titular'  => $t->titular,
                'telefono' => $t->telefono,
            ])->values(),
            'recursando'       => $alumno->recursandos->map(fn($r) => [
            'anio'     => $r->anio ? \App\Support\CourseLabel::anio($r->anio) : null,
                'division' => $r->division,
                'materia'  => $r->materia?->nombre ?? '',
            ])->values(),
            'intensificacion'  => $alumno->intensificaciones->map(fn($r) => [
                'anio'     => $r->anio ? \App\Support\CourseLabel::anio($r->anio) : null,
                'division' => $r->division,
                'materia'  => $r->materia?->nombre ?? '',
            ])->values(),

            'responsables'     => $alumno->responsables->map(fn($r) => [
                'nombre'      => $r->nombre,
                'apellido'    => $r->apellido,
                'parentesco'  => $r->parentesco,
                'convive'     => (bool) $r->convive,
                'tutor_legal' => (bool) $r->tutor_legal,
            ])->values(),
        ]);
    }

    // Crea un nuevo alumno con datos personales, direcciones, telefonos, responsables, recursadas e intensificaciones.
    // POST /api/alumnos
    public function store(Request $req)
    {
        $data = $this->validatePayloadDinamico($req);

        return DB::transaction(function () use ($data, $req) {
            $alumno = Alumno::create([
                'nombre'           => $data['nombre'],
                'apellido'         => $data['apellido'],
                'genero'           => $data['genero'],
                'nacionalidad'     => $data['nacionalidad'] ?? null,
                'dni'              => $data['dni'] ?? null,
                'matricula'        => $data['matricula'] ?? null,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'anio'             => $data['anio'] ?? null,
                'division'         => $data['division'] ?? null, // ya viene normalizada
                'grupo'            => $data['grupo'] ?? null,
                'estado'           => $data['estado'] ?? 'Activo',
                'especialidad'     => $data['especialidad'] ?? null,
                'observaciones'    => $data['observaciones'] ?? null,
                'tiene_ppi'        => (bool) ($data['tiene_ppi'] ?? false),
                'tiene_ae'         => (bool) ($data['tiene_ae']  ?? false),
            ]);

            foreach ($data['direcciones'] ?? [] as $d) $alumno->direcciones()->create($d);
            foreach ($data['telefonos']   ?? [] as $t) $alumno->telefonos()->create($t);

            // responsables
            foreach ($data['responsables'] ?? [] as $r) {
                $alumno->responsables()->create([
                    'nombre'      => trim((string)($r['nombre'] ?? '')),
                    'apellido'    => trim((string)($r['apellido'] ?? '')),
                    'parentesco'  => trim((string)($r['parentesco'] ?? $r['vinculo'] ?? '')),
                    'convive'     => (bool)($r['convive'] ?? false),
                    'tutor_legal' => (bool)($r['tutor_legal'] ?? false),
                ]);
            }

            // recursando
            $rec = [];
            foreach ($data['recursando'] ?? [] as $r) {
                $divRec = $this->normalizeDivision($r['division'] ?? null);
                $rec[] = [
                    'anio'           => (int) $r['anio'],
                    'division'       => $divRec,
                    'materia_id'     => $this->findMateriaId((int) $r['anio'], (string) ($r['materia'] ?? ''), $divRec),
                    'materia_nombre' => trim((string)($r['materia'] ?? '')),
                ];
            }
            $rec = $this->dedupeRecInt($rec);
            foreach ($rec as $r) $alumno->recursandos()->create($r);

            // intensificación
            $int = [];
            foreach ($data['intensificacion'] ?? [] as $r) {
                $divInt = $this->normalizeDivision($r['division'] ?? null);
                $int[] = [
                    'anio'           => (int) $r['anio'],
                    'division'       => $divInt,
                    'materia_id'     => $this->findMateriaId((int) $r['anio'], (string) ($r['materia'] ?? ''), $divInt),
                    'materia_nombre' => trim((string)($r['materia'] ?? '')),
                ];
            }
            $int = $this->dedupeRecInt($int);
            foreach ($int as $r) $alumno->intensificaciones()->create($r);

            ActivityLogger::log($req, 'Creó alumno ' . $alumno->apellido . ', ' . $alumno->nombre . ' (' . $alumno->dni . ')', 'alumno.store', 'Alumno', $alumno->id, null, ['nombre' => $alumno->nombre, 'apellido' => $alumno->apellido, 'dni' => $alumno->dni, 'anio' => $alumno->anio, 'division' => $alumno->division]);

        $anioLabel = \App\Support\CourseLabel::label($alumno->anio, $alumno->division);

            return response()->json([
                'id'               => $alumno->id,
                'nombre'           => $alumno->nombre,
                'apellido'         => $alumno->apellido,
                'genero'           => $alumno->genero,
                'nacionalidad'     => $alumno->nacionalidad,
                'dni'              => $alumno->dni,
                'matricula'        => $alumno->matricula,
                'fecha_nacimiento' => $alumno->fecha_nacimiento?->format('Y-m-d'),
                'anio'             => $anioLabel,
                'anio_num'         => $alumno->anio,
                'division'         => $alumno->division,
                'grupo'            => $alumno->grupo, // Grupo A/B dentro de la division
                'estado'           => $alumno->estado,
                'observaciones'    => $alumno->observaciones,
            ], 201);
        });
    }

    // Actualiza los datos de un alumno existente, reemplazando direcciones, telefonos, responsables, recursadas e intensificaciones.
    // PUT /api/alumnos/{alumno}
    public function update(Request $req, Alumno $alumno)
    {
        $this->assertCanWriteAlumno($req, $alumno);
        $data = $this->validatePayloadDinamico($req, $alumno->id);

        return DB::transaction(function () use ($alumno, $data, $req) {
            // La ficha reemplaza direcciones, telefonos y responsables de una
            // corrida. Si dos personas la tienen abierta a la vez, la segunda
            // pisaba el trabajo de la primera sin avisar. Se vuelve a leer la
            // fila con lock (el segundo guardado espera y ve la version nueva)
            // y se corta en 409 solo cuando el cliente participa del control;
            // sin "_version" se guarda como siempre.
            $bloqueada = Alumno::whereKey($alumno->id)->lockForUpdate()->first();
            if ($bloqueada) $alumno = $bloqueada;

            $version = $req->input('_version');
            $actual  = (int) ($alumno->updated_at?->getTimestamp() ?? 0);
            if ($version !== null && $version !== '' && (int) $version !== $actual) {
                return response()->json([
                    'message'   => 'Otra persona guardó este alumno mientras tenías la ficha abierta. Para no pisar su trabajo no se aplicó nada: recargá la ficha y volvé a hacer el cambio.',
                    'conflicto' => true,
                    'version'   => $actual,
                ], 409);
            }

            $estadoReq = trim((string)$req->input('estado', $alumno->estado ?? 'Activo'));
            if ($estadoReq === '') $estadoReq = 'Activo';
            // El estado solo admite Activo o Egresado (insensible a mayúsculas).
            // Cualquier otro valor se rechaza en criollo en vez de guardarse.
            $estadoReq = ucfirst(strtolower($estadoReq));
            if (!in_array($estadoReq, ['Activo', 'Egresado'], true)) {
                return response()->json([
                    'message' => 'El estado debe ser Activo o Egresado.',
                    'errors'  => ['estado' => ['El estado debe ser Activo o Egresado.']],
                ], 422);
            }

            $oldValues = [
                'nombre'           => $alumno->nombre,
                'apellido'         => $alumno->apellido,
                'genero'           => $alumno->genero,
                'nacionalidad'     => $alumno->nacionalidad,
                'dni'              => $alumno->dni,
                'matricula'        => $alumno->matricula,
                'fecha_nacimiento' => $alumno->fecha_nacimiento?->format('Y-m-d'),
                'anio'             => $alumno->anio,
                'division'         => $alumno->division,
                'grupo'            => $alumno->grupo,
                'estado'           => $alumno->estado,
                'especialidad'     => $alumno->especialidad,
                'observaciones'    => $alumno->observaciones,
                'tiene_ppi'        => $alumno->tiene_ppi,
                'tiene_ae'         => $alumno->tiene_ae,
            ];

            // Snapshot de recursando/intensificacion antes del reemplazo.
            $oldRel = [
                'recursando'      => $alumno->recursandos()->orderBy('id')->get(['anio', 'division', 'materia_id', 'materia_nombre'])->toArray(),
                'intensificacion' => $alumno->intensificaciones()->orderBy('id')->get(['anio', 'division', 'materia_id', 'materia_nombre'])->toArray(),
            ];

            // Si se marca Egresado sin datos de egreso, se completan desde el curso
            // que se está guardando (igual que egresar()): evita filas a medio
            // egresar que las promociones leerían distinto.
            if ($estadoReq === 'Egresado' && empty($alumno->egreso_year)) {
                $alumno->egreso_year = (int) date('Y');
                $alumno->egreso_anio = $data['anio'] ?? $alumno->anio;
                $alumno->egreso_division = $data['division'] ?? $alumno->division;
            }

            $alumno->update([
                'nombre'           => $data['nombre'],
                'apellido'         => $data['apellido'],
                'genero'           => $data['genero'],
                'nacionalidad'     => $data['nacionalidad'] ?? null,
                'dni'              => $data['dni'] ?? null,
                'matricula'        => $data['matricula'] ?? null,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'anio'             => $data['anio'] ?? null,
                'division'         => $data['division'] ?? null,
                'grupo'            => $data['grupo'] ?? null,
                'estado'           => $estadoReq,
                'especialidad'     => $data['especialidad'] ?? null,
                'observaciones'    => $data['observaciones'] ?? null,
                'tiene_ppi'        => (bool) ($data['tiene_ppi'] ?? $alumno->tiene_ppi ?? false),
                'tiene_ae'         => (bool) ($data['tiene_ae']  ?? $alumno->tiene_ae  ?? false),
            ]);

            $alumno->direcciones()->delete();
            foreach ($data['direcciones'] ?? [] as $d) $alumno->direcciones()->create($d);

            $alumno->telefonos()->delete();
            foreach ($data['telefonos'] ?? [] as $t)   $alumno->telefonos()->create($t);

            // responsables
            $alumno->responsables()->delete();
            foreach ($data['responsables'] ?? [] as $r) {
                $alumno->responsables()->create([
                    'nombre'      => trim((string)($r['nombre'] ?? '')),
                    'apellido'    => trim((string)($r['apellido'] ?? '')),
                    'parentesco'  => trim((string)($r['parentesco'] ?? $r['vinculo'] ?? '')),
                    'convive'     => (bool)($r['convive'] ?? false),
                    'tutor_legal' => (bool)($r['tutor_legal'] ?? false),
                ]);
            }

            // recursando (replace)
            $rec = [];
            foreach ($data['recursando'] ?? [] as $r) {
                $divRec = $this->normalizeDivision($r['division'] ?? null);
                $rec[] = [
                    'anio'           => (int) $r['anio'],
                    'division'       => $divRec,
                    'materia_id'     => $this->findMateriaId((int) $r['anio'], (string) ($r['materia'] ?? ''), $divRec),
                    'materia_nombre' => trim((string)($r['materia'] ?? '')),
                ];
            }
            $rec = $this->dedupeRecInt($rec);
            $alumno->recursandos()->delete();
            foreach ($rec as $r) $alumno->recursandos()->create($r);

            // intensificación (replace)
            $int = [];
            foreach ($data['intensificacion'] ?? [] as $r) {
                $divInt = $this->normalizeDivision($r['division'] ?? null);
                $int[] = [
                    'anio'           => (int) $r['anio'],
                    'division'       => $divInt,
                    'materia_id'     => $this->findMateriaId((int) $r['anio'], (string) ($r['materia'] ?? ''), $divInt),
                    'materia_nombre' => trim((string)($r['materia'] ?? '')),
                ];
            }
            $int = $this->dedupeRecInt($int);
            $alumno->intensificaciones()->delete();
            foreach ($int as $r) $alumno->intensificaciones()->create($r);

            // Sincroniza los campos de egreso según el estado resultante.
            if (strcasecmp($alumno->estado, 'Egresado') === 0) {
                // Si pasa a egresado, registra el año lectivo, anio y division desde los que egresa.
                if (empty($alumno->egreso_year)) {
                    $alumno->egreso_year     = (int) ($req->input('anioLectivo') ?? date('Y'));
                }
                $alumno->egreso_anio     = $alumno->anio;
                $alumno->egreso_division = $alumno->division;
                $alumno->save();
            } else {
                // Si ya no está egresado, limpia los campos de egreso.
                $alumno->egreso_year     = null;
                $alumno->egreso_anio     = null;
                $alumno->egreso_division = null;
                $alumno->save();
            }

            // La version de concurrencia tiene que avanzar aunque el guardado
            // solo haya tocado hijos (un telefono o un responsable no ensucia
            // los campos del alumno, pero es una edicion real de la ficha).
            $alumno->touch();

            // Historial: se manda la foto completa y el diff centralizado
            // (ActivityLogger) deja solo los campos que realmente cambiaron.
            // Si no cambió nada, no se genera entrada en el historial.
            ActivityLogger::log(
                $req,
                'Actualizó alumno ' . $alumno->apellido . ', ' . $alumno->nombre,
                'alumno.update',
                'Alumno',
                $alumno->id,
                // $oldRel son las relaciones ANTES del reemplazo; sin ellas el
                // diff las daría como cambiadas siempre.
                $oldValues + $oldRel,
                [
                    'nombre'           => $alumno->nombre,
                    'apellido'         => $alumno->apellido,
                    'genero'           => $alumno->genero,
                    'nacionalidad'     => $alumno->nacionalidad,
                    'dni'              => $alumno->dni,
                    'matricula'        => $alumno->matricula,
                    'fecha_nacimiento' => $alumno->fecha_nacimiento?->format('Y-m-d'),
                    'anio'             => $alumno->anio,
                    'division'         => $alumno->division,
                    'grupo'            => $alumno->grupo,
                    'estado'           => $alumno->estado,
                    'especialidad'     => $alumno->especialidad,
                    'observaciones'    => $alumno->observaciones,
                    'tiene_ppi'        => $alumno->tiene_ppi,
                    'tiene_ae'         => $alumno->tiene_ae,
                    // Relaciones: el diff profundo ignora el orden en que llegan.
                    'recursando'       => $alumno->recursandos()->orderBy('id')->get(['anio', 'division', 'materia_id', 'materia_nombre'])->toArray(),
                    'intensificacion'  => $alumno->intensificaciones()->orderBy('id')->get(['anio', 'division', 'materia_id', 'materia_nombre'])->toArray(),
                ]
            );

            return $this->show($req, $alumno);
        });
    }

    // Elimina un alumno y todos sus datos asociados (direcciones, telefonos, recursadas, intensificaciones, responsables).
    // DELETE /api/alumnos/{alumno}
    public function destroy(Request $req, Alumno $alumno)
    {
        // Borrado en cascada irreversible: exige contraseña reciente.
        $this->assertPasswordConfirmed($req);

        $nombre = $alumno->apellido . ', ' . $alumno->nombre;
        return DB::transaction(function () use ($alumno, $req, $nombre) {
            $alumno->direcciones()->delete();
            $alumno->telefonos()->delete();
            $alumno->recursandos()->delete();
            $alumno->intensificaciones()->delete();
            $alumno->responsables()->delete();
            $alumno->delete();

            ActivityLogger::log($req, 'Eliminó alumno ' . $nombre, 'alumno.destroy', 'Alumno', $alumno->id);

            return response()->json(['ok' => true]);
        });
    }

    // Marca a un alumno como egresado, registrando el anio lectivo, el anio y la division desde la que egresa.
    // Solo permite egresar alumnos que esten cursando el anio maximo activo.
    /* ===================== Egresar ===================== */
    public function egresar(Request $req, Alumno $alumno)
    {
        $maxAnio = $this->dynamicMaxYear();
        // Solo permite egresar alumnos que esten cursando el anio maximo activo.
        if ((int) $alumno->anio !== (int) $maxAnio) {
            return response()->json([
                'message' => "Sólo se puede egresar alumnos de " . \App\Support\CourseLabel::anio($maxAnio) . "."
            ], 422);
        }

        $anioLectivo = (int) ($req->input('anioLectivo') ?? date('Y'));

        $alumno->estado           = 'Egresado';
        $alumno->egreso_year      = $anioLectivo;
        $alumno->egreso_anio      = $alumno->anio;
        $alumno->egreso_division  = $alumno->division;
        $alumno->save();

        ActivityLogger::log($req, 'Egresó alumno ' . $alumno->apellido . ', ' . $alumno->nombre, 'alumno.egresar', 'Alumno', $alumno->id);

        return response()->json([
            'id'              => $alumno->id,
            'estado'          => $alumno->estado,
            'egreso_year'     => $alumno->egreso_year,
            'egreso_anio'     => $alumno->egreso_anio,
            'egreso_division' => $alumno->egreso_division,
        ]);
    }

    /* ===================== Validación dinámica ===================== */

    // Valida los datos del formulario de alumno de forma dinamica segun la configuracion activa de anios y divisiones.
    // Normaliza genero, divisiones, filtra responsables vacios y verifica que las divisiones sean validas para cada anio.
    private function validatePayloadDinamico(Request $req, ?int $ignoreId = null): array
    {
        $maxAnio = $this->dynamicMaxYear();

        // Normalizar 'vinculo' → 'parentesco'
        $input = $req->all();
        // DNI vacío (string '') equivale a sin DNI: evita falsos "duplicado"
        // por unique cuando varios alumnos no tienen DNI cargado.
        if (array_key_exists('dni', $input) && trim((string) $input['dni']) === '') {
            $input['dni'] = null;
        }
        // Nacionalidad y Nro. de matrícula vacío = sin dato (nunca un string con
        // espacios), y se guarda tal como se escribe pero sin sobrantes.
        foreach (['nacionalidad', 'matricula'] as $campoTexto) {
            if (!array_key_exists($campoTexto, $input)) continue;
            $t = trim((string) $input[$campoTexto]);
            $input[$campoTexto] = $t === '' ? null : $t;
        }
        if (isset($input['responsables']) && is_array($input['responsables'])) {
            foreach ($input['responsables'] as &$r) {
                $p = isset($r['parentesco']) ? trim((string)$r['parentesco']) : '';
                $v = isset($r['vinculo']) ? trim((string)$r['vinculo']) : '';
                if ($p === '' && $v !== '') $r['parentesco'] = $v;
            }
            unset($r);
        }

        $rules = [
            'nombre'           => ['required','string','max:120'],
            'apellido'         => ['required','string','max:120'],
            'genero'           => ['required','string', Rule::in(['F','M','O','Femenino','Masculino','Otros'])],
            'nacionalidad'     => ['nullable','string','max:60'],
            'dni'              => ['nullable','string','max:20', Rule::unique('alumnos','dni')->ignore($ignoreId)],
            'matricula'        => ['nullable','string','max:30'],
            'fecha_nacimiento' => ['nullable','date'],
            'anio'             => ['required','integer','min:1','max:'.$maxAnio],
            'division'         => ['nullable','string','size:1'],
            'grupo'            => ['nullable','string','size:1', Rule::in(['A','B'])],
            'observaciones'    => ['nullable','string'],
            'especialidad'     => ['nullable','string', Rule::in(array_keys(\App\Dominios\Alumnos\Alumno::ESPECIALIDADES))],

            'tiene_ppi'        => ['nullable','boolean'],
            'tiene_ae'         => ['nullable','boolean'],

            'direcciones'              => ['array'],
            'direcciones.*.titular'    => ['required','string','max:100'],
            'direcciones.*.direccion'  => ['required','string','max:255'],

            'telefonos'                => ['array'],
            'telefonos.*.titular'      => ['required','string','max:100'],
            'telefonos.*.telefono'     => ['required','string','max:40'],

            'recursando'               => ['array'],
            'recursando.*.anio'        => ['required','integer','min:1','max:'.$maxAnio],
            'recursando.*.division'    => ['nullable','string','size:1'],
            'recursando.*.materia'     => ['required','string','max:120'],

            'intensificacion'           => ['array'],
            'intensificacion.*.anio'    => ['required','integer','min:1','max:'.$maxAnio],
            'intensificacion.*.division'=> ['nullable','string','size:1'],
            'intensificacion.*.materia' => ['required','string','max:120'],

            'responsables'               => ['array'],
            'responsables.*.nombre'      => ['nullable','string','max:120'],
            'responsables.*.apellido'    => ['nullable','string','max:120'],
            'responsables.*.parentesco'  => ['nullable','string','max:40'],
            'responsables.*.vinculo'     => ['nullable','string','max:40'],
            'responsables.*.convive'     => ['nullable','boolean'],
            'responsables.*.tutor_legal' => ['nullable','boolean'],
        ];

        $messages = [
            'anio.max'                   => "El año no puede ser mayor que {$maxAnio} (tope activo).",
            'recursando.*.anio.max'      => "El año de 'recursando' no puede ser mayor que {$maxAnio}.",
            'intensificacion.*.anio.max' => "El año de 'intensificación' no puede ser mayor que {$maxAnio}.",
        ];

        $validator = Validator::make($input, $rules, $messages);

        // Verifica que la division seleccionada este activa para el anio elegido, tanto en el alumno como en recursadas e intensificaciones.
        // división debe pertenecer a las activas del año elegido
        $validator->after(function ($v) use ($input) {
            $anio = (int) ($input['anio'] ?? 0);
            $division = strtoupper((string) ($input['division'] ?? ''));
            if ($anio > 0 && $division !== '') {
                $valid = $this->validDivisionsForYear($anio);
                if (!empty($valid) && !in_array($division, $valid, true)) {
                    $v->errors()->add('division', "La división seleccionada no está activa para " . \App\Support\CourseLabel::anio($anio) . ".");
                }
            }

            foreach (['recursando','intensificacion'] as $campo) {
                $items = (array) ($input[$campo] ?? []);
                foreach ($items as $idx => $r) {
                    $a = (int) ($r['anio'] ?? 0);
                    $d = strtoupper((string) ($r['division'] ?? ''));
                    if ($a > 0 && $d !== '') {
                        $valid = $this->validDivisionsForYear($a);
                        if (!empty($valid) && !in_array($d, $valid, true)) {
                            $v->errors()->add("{$campo}.{$idx}.division", "La división '{$d}' no está activa para " . \App\Support\CourseLabel::anio($a) . ".");
                        }
                    }
                }
            }

            // La especialidad solo aplica a partir de 4to año.
            $esp = $input['especialidad'] ?? null;
            if (!empty($esp) && $anio < 4) {
                $v->errors()->add('especialidad', 'La especialidad solo se puede elegir a partir de 4to año.');
            }
        });

        if ($validator->fails()) throw new ValidationException($validator);

        $data = $validator->validated();

        // Normaliza el genero a una sola letra (F, M, O) y las divisiones a mayuscula.
        // Normalizar género a F/M/O
        $g = strtoupper((string) $data['genero']);
        $map = ['FEMENINO' => 'F', 'MASCULINO' => 'M', 'OTROS' => 'O'];
        $data['genero'] = $map[$g] ?? substr($g, 0, 1);

        // Normalizar divisiones
        $data['division'] = $this->normalizeDivision($data['division'] ?? null);
        foreach (['recursando','intensificacion'] as $campo) {
            if (!empty($data[$campo]) && is_array($data[$campo])) {
                $data[$campo] = array_map(function ($r) {
                    $r['division'] = $this->normalizeDivision($r['division'] ?? null);
                    return $r;
                }, $data[$campo]);
            }
        }

        // Elimina los responsables que no tienen nombre, apellido, parentesco ni vinculo y no son tutor legal ni conviviente.
        // Filtrar responsables vacíos
        if (isset($data['responsables']) && is_array($data['responsables'])) {
            $data['responsables'] = array_values(array_filter($data['responsables'], function($r){
                $n = trim((string)($r['nombre']      ?? ''));
                $a = trim((string)($r['apellido']    ?? ''));
                $p = trim((string)($r['parentesco']  ?? ''));
                $v = trim((string)($r['vinculo']     ?? ''));
                $hasFlags = (bool)($r['tutor_legal'] ?? false) || (bool)($r['convive'] ?? false);
                return ($n !== '' || $a !== '' || $p !== '' || $v !== '' || $hasFlags);
            }));
        }

        return $data;
    }

    // Descarga el padrón en Excel (.xlsx) con los filtros que se están viendo.
    // GET /api/alumnos/padron/excel?q=&anio=&division=&genero=&especialidad=&orden=
    //
    // Se reemplazó la antigua descarga en CSV: el CSV traía 8 columnas sueltas,
    // sin formato y con los acentos al azar según el programa que lo abriera.
    // Acá va una sola consulta con las relaciones precargadas (nada de N+1 sobre
    // 1200 alumnos), una hoja PADRÓN con todos los datos del expediente y una
    // hoja RESUMEN por curso, con las condiciones y los datos faltantes.
    public function exportarPadron(Request $req)
    {
        $q        = trim((string) $req->query('q', ''));
        $anio     = (int) $req->query('anio');
        $division = strtoupper(trim((string) $req->query('division', '')));
        $generoRaw = strtoupper((string) $req->query('genero', ''));
        $mapG = ['FEMENINO' => 'F', 'MASCULINO' => 'M', 'OTROS' => 'O', 'OTRO' => 'O'];
        $genero = $mapG[$generoRaw] ?? $generoRaw;
        $especialidad = strtolower(trim((string) $req->query('especialidad', '')));
        $orden  = (string) $req->query('orden', 'apellido-az');

        $query = Alumno::query()
            ->with([
                'direcciones', 'telefonos', 'responsables',
                'recursandos.materia', 'intensificaciones.materia',
            ]);

        // El mismo alcance que ve la pantalla: profe/preceptor sólo lo suyo.
        $this->scopeAlumnoIndex($req, $query);

        if ($q !== '') {
            $like = LikeSearch::contains($q);
            $query->where(function ($qq) use ($like) {
                $qq->where('nombre', 'like', $like)
                   ->orWhere('apellido', 'like', $like)
                   ->orWhere('dni', 'like', $like)
                   ->orWhere('matricula', 'like', $like);
            });
        }
        if (in_array($genero, ['F', 'M', 'O'], true)) $query->where('genero', $genero);
        if ($anio)                                    $query->where('anio', $anio);
        if ($division)                                $query->where('division', $division);
        if ($especialidad !== '' && $especialidad !== 'todos') {
            $query->where('especialidad', $especialidad);
        }

        // Mismos criterios de orden que el selector de la pantalla.
        match ($orden) {
            'apellido-za' => $query->orderByDesc('apellido')->orderByDesc('nombre'),
            'nuevos'      => $query->orderByDesc('created_at')->orderByDesc('id'),
            'viejos'      => $query->orderBy('created_at')->orderBy('id'),
            default       => $query->orderBy('apellido')->orderBy('nombre'),
        };

        $filas = [];
        $n = 0;
        foreach ($query->get() as $a) {
            $n++;
            $filas[] = $this->filaPadron($a, $n);
        }

        $meta = [
            'encabezado_1' => config('planilla.encabezado_1'),
            'encabezado_2' => config('planilla.encabezado_2'),
            'escuela'      => config('planilla.escuela'),
            'titulo'       => 'PADRÓN DE ALUMNOS',
            'ciclo'        => now()->year,
            'filtros'      => $this->filtrosParaTitulo($q, $anio, $division, $genero, $especialidad),
        ];

        $excel = new PadronExcel();
        $excel->armar($filas, $meta);

        return $this->respuestaPadron($excel);
    }

    /** Arma la respuesta con el .xlsx ya generado. */
    private function respuestaPadron(PadronExcel $excel)
    {
        $contenido = $excel->contenido();

        // Todos los descargos salen con el mismo nombre: el filtro que se usó ya
        // está escrito dentro del archivo, en la línea de datos de la hoja PADRÓN.
        $nombre = 'lista_alumnos.xlsx';

        // Cuerpo y Content-Length del mismo bytes: si se generara dos veces la
        // descarga llegaría cortada.
        return response($contenido, 200, array_merge(
            Descarga::xlsx($nombre),
            ['Content-Length' => (string) strlen($contenido)]
        ));
    }

    /** Texto legible de los filtros, para que el archivo diga cómo se sacó. */
    private function filtrosParaTitulo(string $q, int $anio, string $division, string $genero, string $especialidad): string
    {
        $partes = [];
        if ($anio || $division !== '') {
            $partes[] = 'Curso ' . CourseLabel::label($anio ?: null, $division ?: null);
        }
        $generos = ['F' => 'Femenino', 'M' => 'Masculino', 'O' => 'Otro'];
        if (isset($generos[$genero])) $partes[] = 'Sexo ' . $generos[$genero];
        if ($especialidad !== '' && $especialidad !== 'todos') {
            $partes[] = 'Especialidad ' . (Alumno::ESPECIALIDADES[$especialidad] ?? $especialidad);
        }
        if ($q !== '') $partes[] = 'Búsqueda "' . $q . '"';
        return implode(' · ', $partes);
    }

    /**
     * Un alumno convertido a la fila del Excel.
     *
     * Las listas (materias, teléfonos, responsables) se pasan con saltos de línea
     * porque la columna tiene el ajuste de texto activado: se leen todas sin
     * agrandar la hoja.
     */
    private function filaPadron(Alumno $a, int $n): array
    {
        $materias = static function ($items): string {
            $salida = [];
            foreach ($items as $r) {
                $nombre = $r->materia?->nombre ?? '';
                if ($nombre === '') continue;
                $curso = $r->anio ? ' (' . CourseLabel::label($r->anio, $r->division) . ')' : '';
                $salida[] = $nombre . $curso;
            }
            return implode("\n", $salida);
        };

        $telefonos = $a->telefonos
            ->map(fn($t) => trim(($t->titular ? $t->titular . ': ' : '') . (string) $t->telefono))
            ->filter()->values()->implode("\n");

        $direcciones = $a->direcciones
            ->map(fn($d) => trim((string) $d->direccion))
            ->filter()->values()->implode("\n");

        $responsables = $a->responsables
            ->map(function ($r) {
                $nombre = trim(($r->apellido ?? '') . ', ' . ($r->nombre ?? ''), ', ');
                $datos  = array_filter([
                    $r->parentesco ? (string) $r->parentesco : '',
                    $r->tutor_legal ? 'tutor legal' : '',
                    $r->convive ? 'convive' : '',
                ]);
                return $nombre !== '' ? $nombre . ($datos ? ' (' . implode(', ', $datos) . ')' : '') : '';
            })
            ->filter()->values()->implode("\n");

        $fecha = $a->fecha_nacimiento;

        return [
            'n'                => $n,
            'apellido'         => $a->apellido,
            'nombre'           => $a->nombre,
            // El DNI va como número: así Excel no lo marca como "texto con
            // formato de número" y se puede ordenar o buscar sin convertir.
            'dni'              => ctype_digit((string) $a->dni) ? (int) $a->dni : $a->dni,
            'matricula'        => $a->matricula,
            'nacionalidad'     => $a->nacionalidad,
            'genero'           => $a->genero,
            'fecha_nacimiento' => $fecha,
            'edad'             => $fecha ? $this->edadAlMomento($fecha) : null,
            'anio'             => $a->anio,
            'division'         => $a->division,
            'grupo'            => $a->grupo,
            'especialidad'     => $a->especialidad_label,
            'estado'           => $a->estado,
            'ppi'              => $a->tiene_ppi ? 'Sí' : '',
            'ae'               => $a->tiene_ae ? 'Sí' : '',
            'recursando'       => $materias($a->recursandos),
            'intensificacion'  => $materias($a->intensificaciones),
            'telefonos'        => $telefonos,
            'direccion'        => $direcciones,
            'responsables'     => $responsables,
            'observaciones'    => $a->observaciones,
            'alta'             => $a->created_at,

            // Datos que lee la hoja RESUMEN (no se escriben en el padrón).
            'tiene_ppi'        => (bool) $a->tiene_ppi,
            'tiene_ae'         => (bool) $a->tiene_ae,
        ];
    }

    /** Edad en años cumplidos al día de hoy. */
    private function edadAlMomento($fecha): int
    {
        return (int) $fecha->diff(now())->y;
    }

    // Lista imprimible de alumnos de un curso (con columna de firma).
    // GET /print/alumnos?anio=&division=[&download=1]
    // Requiere autenticación (middleware auth.token: Bearer o ?ticket=) con alcance al curso.
    public function printListado(Request $req)
    {
        $user = $req->user();
        if (!$user) {
            abort(401, 'No autorizado. Iniciá sesión para ver el listado.');
        }

        $anio     = $req->integer('anio');
        $division = strtoupper(trim((string) $req->query('division', '')));

        if (!$anio) {
            abort(422, 'Falta el parámetro: anio');
        }

        $req->setUserResolver(fn() => $user);
        $this->assertCanReadCurso($req, $anio, $division ?: null);

        $alumnos = Alumno::where('anio', $anio)
            ->when($division, fn ($q) => $q->where('division', $division))
            ->orderBy('apellido')->orderBy('nombre')
            ->get(['id', 'apellido', 'nombre', 'dni', 'estado']);

        $viewData = [
            'titulo'   => 'Listado de alumnos',
            'curso'    => CourseLabel::label($anio, $division ?: null),
            'fecha'    => now()->format('d/m/Y'),
            'alumnos'  => $alumnos,
        ];

        if ($req->query('download') === '1') {
            $html = view('alumnos.listado', $viewData)->render();
            return Pdf::loadHTML($html)
                ->setPaper('A4', 'portrait')
                ->download("listado_{$anio}_{$division}.pdf");
        }

        return response()->view('alumnos.listado', $viewData);
    }
}



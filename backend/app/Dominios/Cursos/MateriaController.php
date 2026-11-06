<?php

namespace App\Dominios\Cursos;

use App\Http\Controllers\Controller;

use App\Dominios\Cursos\Materia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\ActivityLogger;

// Controlador para la gestion de materias por anio.
// Permite listar, reemplazar, renombrar y eliminar materias.
class MateriaController extends Controller
{
    use \App\Support\AuthorizesScope;
    /**
     * GET /anios/{anio}/materias
     * Devuelve SOLO materias activas si la columna 'activo' existe; si no existe, devuelve todas.
     */
    // Lista las materias de un anio determinado, ordenadas alfabeticamente.
    public function indexByAnio($anio)
    {
        $anio = (int) $anio;

        $hasActivo = Cache::remember('schema.materias.activo', 3600, fn() => Schema::hasColumn('materias', 'activo'));

        $q = Materia::query()
            ->where('anio', $anio);

        if ($hasActivo) {
            $q->where('activo', 1);
        }

        $items = $q->orderBy('nombre')->get(['id','nombre','anio','tipo','especialidad']);

        return response()->json($items);
    }

    /**
     * GET /materias/todas
     * Devuelve todas las materias agrupadas por año en un solo request.
     * Cache 1 hora porque cambian muy poco.
     */
    public function todas()
    {
        $hasActivo = Cache::remember('schema.materias.activo', 3600, fn() => Schema::hasColumn('materias', 'activo'));

        $grouped = Cache::remember('materias.todas', 3600, function () use ($hasActivo) {
            $q = Materia::query();
            if ($hasActivo) {
                $q->where('activo', 1);
            }
            $all = $q->orderBy('nombre')->get(['id', 'nombre', 'anio', 'tipo', 'especialidad']);
            return $all->groupBy('anio')->map(fn($items) => $items->values())->toArray();
        });

        return response()->json($grouped);
    }

    /**
     * PUT /anios/{anio}/materias
     * Reemplaza el set de materias de un año, preservando IDs en renombres.
     * Si NO existe 'activo', no desactiva ni borra (evita romper FKs).
     *
     * Request: { materias: [ {id?, nombre}, ... ] }  // también admite ["Matemática", "Lengua"]
     */
    // Reemplaza el listado completo de materias de un anio: crea las nuevas, actualiza las existentes
    // y desactiva las que ya no estan presentes. Todo en una transaccion con rollback automatico.
    public function replaceByAnio(Request $request, $anio)
    {
        $anio = (int) $anio;
        // Reemplazo masivo de materias: exige contraseña reciente.
        $this->assertPasswordConfirmed($request);
        $this->assertCanWriteDivision($request, $anio, null);

        // El motivo es opcional: si viene vacío, el historial guarda "sin motivo".
        $request->validate([
            '_detalles' => 'nullable|string|max:1000',
        ]);

        $tiposValidos  = ['aula', 'taller', 'especialidad'];
        $especValidas  = ['informatica', 'mmo', 'electromecanica', 'quimica'];

        // Normaliza el payload
        $raw = collect($request->input('materias', []));
        $limpia = $raw->map(function ($item) use ($tiposValidos, $especValidas) {
            if (is_array($item)) {
                $id = isset($item['id']) ? (int)$item['id'] : null;
                $nombre = trim(preg_replace('/\s+/', ' ', (string)($item['nombre'] ?? '')));
                if ($nombre === '') return null;
                $tipo = isset($item['tipo']) ? strtolower(trim((string)$item['tipo'])) : 'aula';
                if (!in_array($tipo, $tiposValidos, true)) $tipo = 'aula';
                $espec = ($tipo === 'especialidad' && !empty($item['especialidad']))
                    ? strtolower(trim((string)$item['especialidad']))
                    : null;
                if ($espec !== null && !in_array($espec, $especValidas, true)) $espec = null;
                return ['id' => $id, 'nombre' => $nombre, 'tipo' => $tipo, 'especialidad' => $espec];
            }
            $nombre = trim(preg_replace('/\s+/', ' ', (string)$item));
            return $nombre !== '' ? ['id' => null, 'nombre' => $nombre, 'tipo' => 'aula', 'especialidad' => null] : null;
        })->filter()->values();

        $keyName = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));

        DB::beginTransaction();
        try {
            $actuales = Materia::where('anio', $anio)->get();
            $byId     = $actuales->keyBy('id');
            $byName   = $actuales->keyBy(fn($m) => $keyName($m->nombre) . '|' . $m->tipo);

            // Snapshot previo para el historial: solo se registraran cambios reales.
            $snapOld = [];
            foreach ($actuales as $m) {
                $snapOld[(string) $m->id] = [
                    'nombre'        => $m->nombre,
                    'tipo'          => $m->tipo,
                    'especialidad'  => $m->especialidad,
                ];
            }

            $usadasIds = [];

            foreach ($limpia as $item) {
                $targetNombre = $item['nombre'];
                $targetKey    = $keyName($targetNombre);
                $targetTipo   = $item['tipo'];
                $targetEspec  = $item['especialidad'];

                if ($item['id'] && $byId->has($item['id'])) {
                    /** @var \App\Dominios\Cursos\Materia $m */
                    $m = $byId[$item['id']];
                    if ($keyName($m->nombre) !== $targetKey || $m->tipo !== $targetTipo) {
                        $dup = Materia::where('anio', $anio)
                            ->where('id', '!=', $m->id)
                            ->whereRaw('LOWER(nombre) = ?', [$targetKey])
                            ->where('tipo', $targetTipo)
                            ->exists();
                        if ($dup) {
                            DB::rollBack();
                            return response()->json(['message' => "Ya existe '{$targetNombre}' (" . $targetTipo . ") en " . \App\Support\CourseLabel::anio($anio) . "."], 422);
                        }
                        $m->nombre = $targetNombre;
                    }
                    $m->tipo = $targetTipo;
                    $m->especialidad = $targetEspec;
                    if (Schema::hasColumn('materias', 'activo')) {
                        $m->activo = 1;
                    }
                    $m->save();
                    $usadasIds[] = $m->id;
                    continue;
                }

                $matchKey = $targetKey . '|' . $targetTipo;
                if ($byName->has($matchKey)) {
                    $m = $byName[$matchKey];
                    $m->nombre = $targetNombre;
                    $m->tipo = $targetTipo;
                    $m->especialidad = $targetEspec;
                    if (Schema::hasColumn('materias', 'activo')) {
                        $m->activo = 1;
                        $m->save();
                    }
                    $usadasIds[] = $m->id;
                    continue;
                }

                $nuevoData = ['anio' => $anio, 'nombre' => $targetNombre, 'tipo' => $targetTipo, 'especialidad' => $targetEspec];
                if (Schema::hasColumn('materias', 'activo')) {
                    $nuevoData['activo'] = 1;
                }
                $nuevo = Materia::create($nuevoData);
                $usadasIds[] = $nuevo->id;
            }

            // Desactivar las que no vinieron SOLO si existe 'activo'
            if (Schema::hasColumn('materias', 'activo')) {
                Materia::where('anio', $anio)
                    ->whereNotIn('id', $usadasIds ?: [0])
                    ->update(['activo' => 0]);
            }

            DB::commit();

            // Invalida la cache de /materias/todas para que los clientes reciban datos frescos.
            Cache::forget('materias.todas');

            // Historial: registrar UNICAMENTE las materias agregadas, quitadas o modificadas.
            $finales = Materia::where('anio', $anio)
                ->when(Schema::hasColumn('materias', 'activo'), fn($q) => $q->where('activo', 1))
                ->orderBy('nombre')
                ->get();

            $snapNew = [];
            foreach ($finales as $m) {
                $snapNew[(string) $m->id] = [
                    'nombre'        => $m->nombre,
                    'tipo'          => $m->tipo,
                    'especialidad'  => $m->especialidad,
                ];
            }

            $cambiosOld = [];
            $cambiosNew = [];

            foreach ($snapOld as $id => $vals) {
                if (!isset($snapNew[$id])) {
                    $cambiosOld['Eliminada: ' . $vals['nombre']] = $vals;   // quitada/desactivada
                    continue;
                }
                if ($vals != $snapNew[$id]) {
                    $etiqueta = '#' . $id;
                    $cambiosOld[$etiqueta] = $vals;
                    $cambiosNew[$etiqueta] = $snapNew[$id];
                }
            }
            foreach ($snapNew as $id => $vals) {
                if (!isset($snapOld[$id])) {
                    $cambiosNew['Nueva: ' . $vals['nombre']] = $vals;      // creada
                }
            }

            if (!empty($cambiosOld) || !empty($cambiosNew)) {
                ActivityLogger::log(
                    $request,
                    'Reemplazó materias de ' . \App\Support\CourseLabel::anio($anio),
                    'materias.replace',
                    'Materia',
                    null,
                    $cambiosOld ?: null,
                    $cambiosNew ?: null
                );
            }

            $ret = Materia::where('anio', $anio)
                ->when(Schema::hasColumn('materias', 'activo'), fn($q) => $q->where('activo', 1))
                ->orderBy('nombre')
                ->get(['id','nombre','anio','tipo','especialidad']);

            return response()->json($ret, 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'No se pudieron guardar las materias.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * PUT /materias/{materia}
     * Renombra una materia puntual (valida duplicado por año) y hace cascada solo donde corresponde.
     */
    // Renombra una materia existente, validando que no haya duplicados en el mismo anio.
    // Si el nombre cambio, propaga el cambio a tablas legacy mediante cascadeRename.
    public function update(Request $request, Materia $materia)
    {
        $this->assertCanWriteDivision($request, $materia->anio, null);

        // El motivo es opcional: si viene vacío, el historial guarda "sin motivo".
        $request->validate([
            '_detalles' => 'nullable|string|max:1000',
        ]);

        $nuevo = $this->cleanOriginal((string) $request->input('nombre'));
        if ($nuevo === '') {
            return response()->json(['message' => 'El nombre es obligatorio.'], 422);
        }

        $dup = Materia::where('anio', $materia->anio)
            ->where('id', '!=', $materia->id)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($this->cleanOriginal($nuevo))])
            ->exists();

        if ($dup) {
            return response()->json(['message' => 'Ya existe una materia con ese nombre en este año.'], 422);
        }

        $old = $materia->nombre;
        if ($old !== $nuevo) {
            DB::transaction(function () use ($materia, $nuevo, $old) {
                $materia->nombre = $nuevo;
                $materia->save();
                $this->cascadeRename($materia->anio, $old, $nuevo, (int) $materia->id);
            });
            Cache::forget('materias.todas');
        }

            ActivityLogger::log($request, 'Renombró materia "' . $old . '" → "' . $nuevo . '" (' . \App\Support\CourseLabel::anio($materia->anio) . ')', 'materias.update', 'Materia', $materia->id, ['nombre' => $old], ['nombre' => $nuevo]);

        return response()->json($materia);
    }

    /**
     * DELETE /materias/{materia}
     */
    // Elimina fisicamente una materia y registra la accion en el historial.
    // Se niega si la materia todavía sostiene datos de alumnos.
    public function destroy(Request $request, Materia $materia)
    {
        $this->assertPasswordConfirmed($request);
        $this->assertCanWriteDivision($request, $materia->anio, null);

        // El borrado deja las notas sin materia (FK en SET NULL) y elimina
        // promedios y estados por cascada. La forma de jubilar una materia es
        // sacarla del listado del año: se desactiva y su historial sigue intacto.
        $nNotas = DB::table('alumno_calificaciones')->where('materia_id', $materia->id)->count();
        $nOtros = DB::table('alumno_promedios')->where('materia_id', $materia->id)->count()
                + DB::table('alumno_materias_estado')->where('materia_id', $materia->id)->count();

        if ($nNotas > 0 || $nOtros > 0) {
            $pedazos = [];
            if ($nNotas > 0) $pedazos[] = $nNotas . ($nNotas === 1 ? ' nota' : ' notas');
            if ($nOtros > 0) $pedazos[] = $nOtros . ($nOtros === 1 ? ' promedio o estado' : ' promedios o estados');

            return response()->json([
                'message' => 'No se puede eliminar "' . $materia->nombre . '" de ' . \App\Support\CourseLabel::anio($materia->anio)
                    . ': tiene ' . implode(' y ', $pedazos) . ' de alumnos cargados y el sistema no borra esos datos.'
                    . ' Quitá la materia del listado del año para que deje de usarse, así el histórico queda guardado.',
            ], 422);
        }

        $request->validate([
            '_detalles' => 'nullable|string|max:1000',
        ]);

        $old = $materia->toArray();
        $materia->delete();
        Cache::forget('materias.todas');

            ActivityLogger::log($request, 'Eliminó materia "' . $old['nombre'] . '" (' . \App\Support\CourseLabel::anio($old['anio']) . ')', 'materias.destroy', 'Materia', $materia->id, $old);

        return response()->json(['ok' => true], 200);
    }

    /* ========================= Helpers ========================= */

    private function cleanOriginal(string $s): string
    {
        return preg_replace('/\s+/u', ' ', trim($s));
    }

    /**
     * Propaga el renombre a las tablas que guardan snapshot denormalizado
     * materia_nombre junto a la FK materia_id. Se matchea por materia_id
     * (exacto y sin ambigüedad de año) en vez de por nombre.
     * No toca horario_celdas, alumno_calificaciones ni profesor_asignaciones
     * (solo usan materia_id, resuelven el nombre por relación).
     */
    private function cascadeRename(int $anio, string $oldName, string $newName, ?int $materiaId = null): void
    {
        $new = $this->cleanOriginal($newName);
        if ($this->cleanOriginal($oldName) === $new || $new === '') return;

        // Si el llamador ya resolvió la materia, usa su id; si no, lo busca
        // por año + nombre nuevo (el rename ya ocurrió en la transacción).
        $materiaId = $materiaId
            ?? Materia::where('anio', $anio)->where('nombre', $new)->value('id');

        if (!$materiaId) return;

        // Tablas con snapshot materia_nombre + FK materia_id (se chequea existencia).
        $targets = [
            'alumno_recursandos',
            'alumno_intensificaciones',
            'alumno_horario_overrides',
        ];

        foreach ($targets as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'materia_nombre')
                && Schema::hasColumn($table, 'materia_id')) {
                DB::table($table)
                    ->where('materia_id', $materiaId)
                    ->update(['materia_nombre' => $new]);
            }
        }
    }
}


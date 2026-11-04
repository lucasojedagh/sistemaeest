<?php

namespace App\Dominios\Cursos;

use App\Http\Controllers\Controller;

use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\ParametroDivision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\ActivityLogger;
use App\Support\AuthorizesScope;

// Controlador para la gestion del ciclo lectivo.
// Permite habilitar nuevos anios y divisiones, asi como ejecutar la promocion
// automatica de alumnos al siguiente anio o su egreso al alcanzar el anio maximo.
class CicloLectivoController extends Controller
{
    use AuthorizesScope;
    // Habilita nuevos anios y divisiones en el sistema, creando los registros en parametro_division
    // y opcionalmente los cursos asociados.
    /**
     * POST /api/admin/years/enable
     * Body:
     * {
     *   "years": [7,8],                            // requerido
     *   "divisions": ["1","2","3","4","5","6","7"],// opcional
     *   "activate_courses": true                   // opcional, crea cursos si faltan
     * }
     */
    public function enableYears(Request $request): JsonResponse
    {
        // Crea/activa años y divisiones: exige contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $data = $request->validate([
            'years'            => 'required|array|min:1',
            'years.*'          => 'integer|min:1|max:12',
            'divisions'        => 'array',
            'divisions.*'      => 'string|regex:/^[0-9]$/',
            'activate_courses' => 'boolean',
        ]);

        $years     = array_values(array_unique($data['years']));
        $divisions = $data['divisions'] ?? ['1','2','3','4','5','6','7'];
        $activate  = (bool)($data['activate_courses'] ?? true);

        $divisions = collect($divisions)
            ->map(fn($d) => (string)$d)
            ->filter(fn($d) => preg_match('/^[0-9]$/', $d))
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($years, $divisions, $activate) {
            foreach ($years as $anio) {
                foreach ($divisions as $div) {
                    ParametroDivision::updateOrCreate(
                        ['anio' => $anio, 'division' => $div],
                        ['activo' => true]
                    );

                    if ($activate) {
                        Curso::firstOrCreate(
                            ['anio' => $anio, 'division' => $div],
                            ['activo' => true]
                        );
                    }
                }
            }
        });

        // El mapa nuevo se devuelve en la respuesta, pero GET /divisiones sirve
        // una caché de 24 h: sin invalidarla el alta no aparece hasta el día siguiente.
        \Illuminate\Support\Facades\Cache::forget('divisiones_index');

        // devolvemos el mapa actualizado
        $map = ParametroDivision::where('activo', true)            ->orderBy('anio')->orderBy('division')
            ->get()
            ->groupBy('anio')
            ->map(fn($rows) => $rows->pluck('division')->values())
            ->toArray();

        ActivityLogger::log($request, 'Habilitó años: ' . implode(', ', $years), 'ciclo.enable_years', 'ParametroDivision', null, null, ['years' => $years, 'divisions' => $divisions]);

        return response()->json($map);
    }

    // Ejecuta la promocion automatica de alumnos al siguiente anio y el egreso de quienes alcanzan el anio maximo.
    // Soporta modo dry_run para previsualizar los cambios sin aplicarlos.
    /**
     * POST /api/ciclo/promocion
     * Body:
     * {
     *   "dry_run": false,   // si true, solo calcula
     *   "max_year": null    // opcional; por defecto toma el mayor año activo en "cursos"
     * }
     *
     * Regla: egresado = setear egreso_year al año calendario actual
     * y guardar egreso_anio / egreso_division desde el curso actual.
     */
    public function promote(Request $request): JsonResponse
    {
        // Promocionar mueve a TODOS los alumnos: exige contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $data = $request->validate([
            'dry_run' => 'boolean',
            'max_year'=> 'nullable|integer|min:1|max:12',
            'sentido' => 'nullable|string',
        ]);

        // Esta ruta solo promueve (sube/egresa). Si alguien le manda sentido=down
        // hay que frenarlo: sino ejecutaría una promoción destructiva creyendo
        // que estaba revirtiendo. La reversión vive en /alumnos/promocionar/aplicar.
        if (strtolower((string) $request->input('sentido', 'up')) === 'down') {
            return response()->json([
                'ok'      => false,
                'message' => 'Esta endpoint solo promociona (hacia adelante). Para revertir una promoción usá /api/alumnos/promocionar/aplicar con sentido=down.',
            ], 422);
        }

        $dry = (bool)($data['dry_run'] ?? false);
        $max = $data['max_year'] ?? (Curso::where('activo', true)->max('anio') ?? 7);

        // Solo alumnos no egresados (egreso_year IS NULL)
        $toPromote = DB::table('alumnos')
            ->whereNull('egreso_year')
            ->where('anio', '<', $max)
            ->count();

        $toGraduate = DB::table('alumnos')
            ->whereNull('egreso_year')
            ->where('anio', '>=', $max)
            ->count();

        if ($dry) {
            return response()->json([
                'dry_run'   => true,
                'max_year'  => (int)$max,
                'promote'   => (int)$toPromote,
                'graduate'  => (int)$toGraduate,
                'done'      => false,
            ]);
        }

        $year = (int) Carbon::now()->year;

        // Ejecuta la promocion dentro de una transaccion: primero egresa a quienes ya estan en el anio maximo o superior,
        // y recién despues sube de anio a los alumnos menores al maximo, evitando que los de anteultimo anio egresen inmediatamente.
        DB::transaction(function () use ($max, $year) {
            // 1) Egresar (setear año calendario + recordar curso desde el que egresó)
            DB::table('alumnos')
                ->select('id','anio','division')
                ->whereNull('egreso_year')
                ->where('anio', '>=', $max)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($year) {
                    foreach ($rows as $r) {
                        DB::table('alumnos')
                            ->where('id', $r->id)
                            ->update([
                                'egreso_year'     => $year,
                                'egreso_anio'     => $r->anio,
                                'egreso_division' => $r->division,
                                'estado'          => 'Egresado', // Marca al alumno como egresado al superar el año maximo
                                'updated_at'      => now(),
                            ]);
                    }
                });

            // 2) Promocionar (subir de año a los que están por debajo del tope)
            DB::table('alumnos')
                ->whereNull('egreso_year')
                ->where('anio', '<', $max)
                ->update([
                    'anio'       => DB::raw('anio + 1'),
                    'updated_at' => now(),
                ]);
        });

        ActivityLogger::log($request, 'Promoción ciclo lectivo: ' . $toPromote . ' promovidos, ' . $toGraduate . ' egresados', 'ciclo.promote', null, null, null, ['promote' => $toPromote, 'graduate' => $toGraduate, 'max_year' => $max]);

        return response()->json([
            'dry_run'   => false,
            'max_year'  => (int)$max,
            'promote'   => (int)$toPromote,
            'graduate'  => (int)$toGraduate,
            'done'      => true,
        ]);
    }
}


<?php

namespace App\Dominios\Cursos;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\ParametroDivision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\ActivityLogger;
use App\Support\AuthorizesScope;

// Controlador para gestionar las divisiones (parametro_divisiones) y su sincronizacion con cursos.
class DivisionController extends Controller
{
    use AuthorizesScope;
    // GET /api/divisiones
    // Devuelve todas las divisiones activas agrupadas por anio, normalizadas y ordenadas.
    public function index()
    {
        $data = Cache::remember('divisiones_index', 86400, function () {
            $map = ParametroDivision::where('activo', true)
                ->orderBy('anio')->orderBy('division')
                ->get()
                ->groupBy('anio')
                ->map(function ($rows) {
                    return $rows->pluck('division')
                        ->map(fn ($d) => strtoupper(trim((string) $d)))
                        ->filter()
                        ->unique()
                        ->values()
                        ->toArray();
                })
                ->toArray();

            // Fill gaps for years 1-7 with empty arrays
            for ($i = 1; $i <= 7; $i++) {
                if (!array_key_exists($i, $map)) $map[$i] = [];
            }

            // If parametro_divisiones is empty (no divisions configured), pull from cursos table
            $hasAnyDivs = collect($map)->flatten()->filter()->isNotEmpty();
            if (!$hasAnyDivs) {
                $cursosDivs = Curso::where('activo', true)
                    ->selectRaw('anio, division')
                    ->distinct()
                    ->orderBy('anio')->orderBy('division')
                    ->get()
                    ->groupBy('anio')
                    ->map(function ($rows) {
                        return $rows->pluck('division')
                            ->map(fn ($d) => strtoupper(trim((string) $d)))
                            ->filter()
                            ->unique()
                            ->values()
                            ->toArray();
                    })
                    ->toArray();

                foreach ($cursosDivs as $anio => $divs) {
                    $map[(int) $anio] = $divs;
                }

                // Also pull from alumnos table for years not covered by cursos
                $alumnoDivs = Alumno::selectRaw('anio, division')
                    ->whereNotNull('anio')->whereNotNull('division')
                    ->distinct()
                    ->orderBy('anio')->orderBy('division')
                    ->get()
                    ->groupBy('anio')
                    ->map(function ($rows) {
                        return $rows->pluck('division')
                            ->map(fn ($d) => strtoupper(trim((string) $d)))
                            ->filter()
                            ->unique()
                            ->values()
                            ->toArray();
                    })
                    ->toArray();

                foreach ($alumnoDivs as $anio => $divs) {
                    $existing = $map[(int) $anio] ?? [];
                    $map[(int) $anio] = array_values(array_unique(array_merge($existing, $divs)));
                }

                // Final fallback: if still empty, use default 1-9 for years 1-7
                $stillEmpty = collect($map)->flatten()->filter()->isEmpty();
                if ($stillEmpty) {
                    $default = ['1','2','3','4','5','6','7','8','9'];
                    for ($i = 1; $i <= 7; $i++) {
                        if (empty($map[$i])) $map[$i] = $default;
                    }
                }
            }

            $normalized = [];
            foreach ($map as $k => $arr) {
                $normalized[(int) $k] = array_values(array_unique(array_map(
                    fn ($d) => strtoupper(trim((string) $d)), (array) $arr
                )));
            }
            ksort($normalized, SORT_NUMERIC);
            return $normalized;
        });

        return response()->json($data);
    }

    public function clearCache()
    {
        Cache::forget('divisiones_index');
        return ['ok' => true];
    }

    // Si tu ruta apunta a /divisiones -> update, delegamos:
    // Delega la actualizacion al metodo bulkUpdate para mantener compatibilidad.
    public function update(Request $request)
    {
        return $this->bulkUpdate($request);
    }

    /**
     * PUT /api/divisiones
     * Body: { "1":["1","2","3"], "6":["1","2","3","4","5","6","7"], ... }
     */
    // Procesa la actualizacion masiva de divisiones: activa/desactiva segun el payload,
    // sincroniza la tabla cursos en una transaccion y registra la actividad.
    public function bulkUpdate(Request $request)
    {
        // Reemplazo masivo de divisiones: exige contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $body = $request->json()->all() ?: $request->all();

        if (!is_array($body)) {
            return response()->json(['message' => 'Formato inválido (se espera objeto {anio:[div,...]})'], 422);
        }

        $validDivs = ['1','2','3','4','5','6','7','8','9'];
        $desired   = [];

        foreach ($body as $k => $divs) {
            $clave = (string) $k;

            // "_detalles" es el motivo que lee el historial, no un año.
            if (str_starts_with($clave, '_')) {
                continue;
            }

            // Un año que no es número tampoco puede pasar de largo: antes el
            // (int) lo aplastaba a 0, el continue lo descartaba y la pantalla
            // recibía un 200 creyendo que había guardado algo.
            if (!preg_match('/^\d+$/', $clave) || (int) $clave <= 0) {
                return response()->json(
                    ['message' => '«' . $clave . '» no es un año válido. Se espera {año:[división,...]} con años del 1 al ' . \App\Support\SchoolYear::LIMITE . '.'],
                    422
                );
            }
            $anio = (int) $clave;

            // Mismo tope que habilitar años y promocionar: sin esto un {"99":["1"]}
            // creaba el curso y el parámetro del año 99 y los dejaba activos.
            if ($anio > \App\Support\SchoolYear::LIMITE) {
                return response()->json(
                    ['message' => 'El año ' . $anio . ' está fuera de rango (1 a ' . \App\Support\SchoolYear::LIMITE . ').'],
                    422
                );
            }

            $list = is_array($divs) ? $divs : [];
            $list = array_map(fn($d) => strtoupper(trim((string)$d)), $list);
            $list = array_values(array_unique(array_filter($list, fn($d) => in_array($d, $validDivs, true))));
            // Los 7 años existen siempre: no se puede dejar un año sin
            // divisiones (eso lo ocultaba del sistema).
            if (empty($list)) {
                return response()->json(
                    ['message' => 'El año ' . $anio . ' tiene que tener al menos una división.'],
                    422
                );
            }
            $desired[$anio] = $list;
        }

        // Estado previo por año (para el historial y para verificar que los
        // años no incluidos en el payload sigan existiendo).
        $oldByYear = ParametroDivision::where('activo', true)->get()
            ->groupBy('anio')
            ->map(fn ($g) => $g->pluck('division')
                ->map(fn ($d) => strtoupper(trim((string) $d)))
                ->unique()->sort()->values()->all())
            ->all();

        // Garantía final: los años 1 a 7 terminan todos con al menos una
        // división activa, combinando lo que viene en el payload con lo que
        // ya había para los años no incluidos.
        foreach (range(1, 7) as $anio) {
            $final = $desired[$anio] ?? $oldByYear[$anio] ?? [];
            if (empty($final)) {
                return response()->json(
                    ['message' => 'El año ' . $anio . ' tiene que tener al menos una división.'],
                    422
                );
            }
        }

        // Transaccion: actualiza parametro_divisiones y cursos para cada anio
        DB::transaction(function () use ($desired) {
            foreach ($desired as $anio => $divsDeseadas) {
                // parametro_divisiones: desactivar todo el año
                ParametroDivision::where('anio', $anio)->update(['activo' => false]);

                // y activar/crear solo las deseadas
                foreach ($divsDeseadas as $div) {
                    ParametroDivision::updateOrCreate(
                        ['anio' => $anio, 'division' => $div],
                        ['activo' => true]
                    );
                }

                // cursos: activar/crear requeridos y desactivar el resto
                $cursos = Curso::where('anio', $anio)->get()
                    ->keyBy(fn($c) => strtoupper((string)$c->division));

                // activar/crear
                foreach ($divsDeseadas as $div) {
                    $div = strtoupper($div);
                    $curso = $cursos->get($div);
                    if ($curso) {
                        if (!$curso->activo) {
                            $curso->activo = true;
                            $curso->save();
                        }
                    } else {
                        // Si tu modelo no tiene fillable, ver sección 3
                        $nuevo = new Curso();
                        $nuevo->anio     = $anio;
                        $nuevo->division = $div;
                        $nuevo->turno    = null;
                        $nuevo->cupo     = null;
                        $nuevo->activo   = true;
                        $nuevo->save();
                    }
                }

                // desactivar los que ya no estén
                $set = array_flip($divsDeseadas);
                foreach ($cursos as $div => $curso) {
                    if (!isset($set[$div]) && $curso->activo) {
                        $curso->activo = false;
                        $curso->save();
                    }
                }
            }
        });

        Cache::forget('divisiones_index');

        // Historial: foto completa por año. El diff centralizado deja solo los
        // años cuyas divisiones cambiaron de verdad.
        $divisionesOld = [];
        $divisionesNew = [];
        foreach ($oldByYear as $anio => $prev) {
            $divisionesOld['Año ' . $anio] = implode(', ', $prev);
        }
        foreach ($desired as $anio => $divs) {
            $divisionesNew['Año ' . $anio] = implode(', ', array_map(
                fn ($d) => strtoupper(trim((string) $d)),
                (array) $divs
            ));
        }

        ActivityLogger::log(
            $request,
            'Actualizó divisiones',
            'divisiones.update',
            'ParametroDivision',
            null,
            $divisionesOld,
            $divisionesNew
        );

        return $this->index();
    }
}


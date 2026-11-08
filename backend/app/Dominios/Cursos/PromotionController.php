<?php

namespace App\Dominios\Cursos;

use App\Http\Controllers\Controller;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Curso;
use App\Dominios\Cursos\ParametroDivision;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Support\ActivityLogger;
use App\Support\AuthorizesScope;
use App\Support\Texto;

// Controlador para la promocion y reversal de alumnos entre anos lectivos.
// Permite subir (promocionar) o bajar (revertir) a los alumnos un anio, gestionando egresos y altas.
class PromotionController extends Controller
{
    use AuthorizesScope;
    /**
     * Normaliza la configuracion recibida del frontend.
     */
    private function parseConfig(Request $request): array
    {
        $sentido = strtolower((string) $request->input('sentido', 'up')); // 'up' | 'down'
        if (!in_array($sentido, ['up', 'down'], true)) $sentido = 'up';

        $anioLectivo = (int) ($request->input('anioLectivo') ?? date('Y') + 1);

        // En la reversión, "anioLectivo" es el ciclo que se quiere deshacer. Si el
        // cliente no lo manda, no se puede inferir del reloj: una promoción de
        // diciembre y su reversión de enero caerían en ciclos distintos y la
        // reversión movería a los activos sin reingresar a los egresados.
        // Se toma el último egreso registrado, que es justamente el ciclo promotions.
        if ($sentido === 'down' && !$request->has('anioLectivo')) {
            $ultimoEgreso = (int) DB::table('alumnos')->where('estado', 'Egresado')->max('egreso_year');
            if ($ultimoEgreso > 0) $anioLectivo = $ultimoEgreso;
        }

        // Normalizo divisiones objetivo (para activar 7/8 si viniera en el payload)
        $base  = (array) $request->input('usarDivisionesBase', ['1','2','3','4','5','6','7','8','9']);
        $extra = (array) $request->input('divisionesExtra', []);
        $divsObjetivo = $this->normDivs(array_merge($base, $extra));

        $agregarAnios = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('agregarAnios', []))
        )));

        return [
            'sentido'      => $sentido,
            'anioLectivo'  => $anioLectivo,
            'divsObjetivo' => $divsObjetivo,
            'agregarAnios' => $agregarAnios,
        ];
    }

    /**
     * Año tope activo (primero por parametro_divisiones; fallback cursos).
     */
    private function maxAnioActivo(): int
    {
        $maxAnio = (int) ParametroDivision::where('activo', true)->max('anio');
        if ($maxAnio <= 0) {
            $maxAnio = (int) Curso::where('activo', true)->max('anio');
        }
        return $maxAnio;
    }

    /**
     * Simula la promoción/reversión SIN aplicar cambios.
     * Devuelve la estructura que consume el modal:
     * {
     *   resumen:  { promocion: [{anio,division,cantidad}], egresan: [...], totales: {promocion, egresan} },
     *   faltantes: { parametro_divisiones: [{anio, divisiones:[]}], cursos: [{anio,division}] }
     * }
     */
    private function buildSimulacion(string $sentido, int $maxAnio, array $agregarAnios, array $divsObjetivo, int $anioLectivo): array
    {
        $promocion = [];
        $egresan   = [];

        if ($sentido === 'down') {
            // Bajan de año los activos con anio > 1
            $promRows = DB::table('alumnos')
                ->select('anio', 'division', DB::raw('COUNT(*) as cantidad'))
                ->whereNotIn('estado', Alumno::ESTADOS_BAJA)
                ->where('anio', '>', 1)
                ->groupBy('anio', 'division')
                ->orderBy('anio')->orderBy('division')
                ->get();

            // Vuelven ÚNICAMENTE los egresados del ciclo que se está revirtiendo:
            // revertir deshace lo que hizo ESA promoción, no las de años anteriores.
            $egrRows = DB::table('alumnos')
                ->select('egreso_anio as anio', 'egreso_division as division', DB::raw('COUNT(*) as cantidad'))
                ->where('estado', 'Egresado')
                ->where('egreso_year', $anioLectivo)
                ->groupBy('egreso_anio', 'egreso_division')
                ->get();

            foreach ($promRows as $r) {
                $promocion[] = ['anio' => (int) $r->anio, 'division' => (string) $r->division, 'cantidad' => (int) $r->cantidad];
            }
            foreach ($egrRows as $r) {
                $anio = (int) $r->anio ?: $maxAnio;
                $div  = (string) $r->division ?: '1';
                $egresan[] = ['anio' => $anio, 'division' => $div, 'cantidad' => (int) $r->cantidad];
            }
        } else {
            // Promocionan (1 → maxAnio-1)
            $promRows = DB::table('alumnos')
                ->select('anio', 'division', DB::raw('COUNT(*) as cantidad'))
                ->whereNotIn('estado', Alumno::ESTADOS_BAJA)
                ->where('anio', '<', $maxAnio)
                ->groupBy('anio', 'division')
                ->orderBy('anio')->orderBy('division')
                ->get();

            // Egresan los que están exactamente en el tope
            $egrRows = DB::table('alumnos')
                ->select('anio', 'division', DB::raw('COUNT(*) as cantidad'))
                ->whereNotIn('estado', Alumno::ESTADOS_BAJA)
                ->where('anio', $maxAnio)
                ->groupBy('anio', 'division')
                ->orderBy('anio')->orderBy('division')
                ->get();

            foreach ($promRows as $r) {
                $promocion[] = ['anio' => (int) $r->anio, 'division' => (string) $r->division, 'cantidad' => (int) $r->cantidad];
            }
            foreach ($egrRows as $r) {
                $egresan[] = ['anio' => (int) $r->anio, 'division' => (string) $r->division, 'cantidad' => (int) $r->cantidad];
            }
        }

        // Faltantes: parametro_divisiones / cursos que faltaría crear
        $faltParam = [];
        foreach ($agregarAnios as $anioNuevo) {
            $activas = ParametroDivision::where('anio', $anioNuevo)
                ->where('activo', true)
                ->get(['division'])
                ->pluck('division')
                ->map(fn ($d) => (string) $d)
                ->all();
            $aCrear = array_values(array_diff($divsObjetivo, $activas));
            if (!empty($aCrear)) {
                $faltParam[] = ['anio' => (int) $anioNuevo, 'divisiones' => $aCrear];
            }
        }

        $combos = [];
        foreach (ParametroDivision::where('activo', true)->get(['anio', 'division']) as $pd) {
            $combos[(int) $pd->anio . '|' . (string) $pd->division] = [(int) $pd->anio, (string) $pd->division];
        }
        foreach ($agregarAnios as $anioNuevo) {
            foreach ($divsObjetivo as $d) {
                $combos[(int) $anioNuevo . '|' . $d] = [(int) $anioNuevo, $d];
            }
        }

        $faltCursos = [];
        foreach ($combos as $combo) {
            if (!Curso::where('anio', $combo[0])->where('division', $combo[1])->exists()) {
                $faltCursos[] = ['anio' => $combo[0], 'division' => $combo[1]];
            }
        }
        usort($faltCursos, function ($a, $b) {
            return [$a['anio'], $a['division']] <=> [$b['anio'], $b['division']];
        });

        return [
            'resumen' => [
                'promocion' => $promocion,
                'egresan'   => $egresan,
                'totales'   => [
                    'promocion' => array_sum(array_column($promocion, 'cantidad')),
                    'egresan'   => array_sum(array_column($egresan, 'cantidad')),
                ],
            ],
            'faltantes' => [
                'parametro_divisiones' => $faltParam,
                'cursos'               => $faltCursos,
            ],
        ];
    }

    /**
     * POST /api/alumnos/promocionar/preview
     * Simula la promoción/reversión sin aplicar cambios, para mostrar el detalle antes de confirmar.
     */
    public function preview(Request $request): JsonResponse
    {
        $cfg = $this->parseConfig($request);

        $maxAnio = $this->maxAnioActivo();
        if ($maxAnio <= 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'No hay años activos en la configuración de divisiones/cursos.',
            ], 422);
        }

        $sim = $this->buildSimulacion($cfg['sentido'], $maxAnio, $cfg['agregarAnios'], $cfg['divsObjetivo'], $cfg['anioLectivo']);

        return response()->json([
            'ok'        => true,
            'accion'    => $cfg['sentido'],
            'maxAnio'   => $maxAnio,
            'message'   => 'Simulación completada. No se aplicó ningún cambio.',
            'resumen'   => $sim['resumen'],
            'faltantes' => $sim['faltantes'],
            'resultado' => $sim,
            // Salvoconducto para /aplicar: sin esta simulación previa no se puede
            // ejecutar el cambio masivo.
            'confirmacion' => $this->registrarConfirmacion($request, $cfg),
        ]);
    }

    // ==== salvoconducto de la simulación ====
    // Mueve a TODOS los alumnos de una corrida, así que aplicar dos veces por
    // error (doble clic, dos pestañas abiertas, "reintentar" del navegador)
    // sube dos años. El preview deja una ficha de un solo uso atada a esta
    // persona y a esta configuración exacta; aplicar la consume. Si cambia un
    // solo parámetro hay que simular de nuevo.
    private const CONFIRMACION_TTL = 600;

    private function claveConfirmacion(int $userId, array $cfg): string
    {
        return 'promocion_confirmada_' . $userId . '_' . sha1(json_encode([
            $cfg['sentido'], $cfg['anioLectivo'], $cfg['agregarAnios'], $cfg['divsObjetivo'],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function registrarConfirmacion(Request $request, array $cfg): string
    {
        $token = \Illuminate\Support\Str::random(32);
        \Illuminate\Support\Facades\Cache::put(
            $this->claveConfirmacion((int) $request->user()->id, $cfg),
            $token,
            self::CONFIRMACION_TTL
        );
        return $token;
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\HttpException si no hay simulación vigente */
    private function exigirConfirmacion(Request $request, array $cfg): void
    {
        $enviado = trim((string) $request->input('confirmacion', ''));
        $guardado = \Illuminate\Support\Facades\Cache::pull($this->claveConfirmacion((int) $request->user()->id, $cfg));

        if ($enviado === '' || !is_string($guardado) || !hash_equals($guardado, $enviado)) {
            abort(422, 'Hay que simular el cambio antes de aplicarlo, y la simulación dura 10 minutos. Volvé a abrir el modal y confirmá sobre el resumen actual.');
        }
    }

    /**
     * POST /api/alumnos/promocionar
     * Body (ejemplos):
     *  - { "sentido": "up",   "anioLectivo": 2026 }
     *  - { "sentido": "down" }
     *  - { "sentido":"up", "agregarAnios":[7,8], "usarDivisionesBase":["1","2","3","4","5","6","7"] }
     */
    public function aplicar(Request $request): JsonResponse
    {
        // Promocionar/revertir mueve a TODOS los alumnos: exige contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $cfg = $this->parseConfig($request);
        $sentido      = $cfg['sentido'];
        $anioLectivo  = $cfg['anioLectivo'];
        $divsObjetivo = $cfg['divsObjetivo'];
        $agregarAnios = $cfg['agregarAnios'];

        // Salvoconducto de la simulación (un solo uso). Se consume antes del lock:
        // si dos pedidos llegan a la vez, el segundo ya no tiene ficha y no mueve nada.
        $this->exigirConfirmacion($request, $cfg);

        // Cadena para que dos corridas no se pisen (doble clic, dos pestañas,
        // dos admin). Dura 15 min por si el servidor corta de golpe: el dueño
        // no puede liberarla, pero al ser un cambio que se hace una vez por año
        // no deja a nadie afuera.
        $lock = \Illuminate\Support\Facades\Cache::lock('promocion_masiva', 900);
        try {
            $lock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            abort(409, 'Ya hay una promoción o reversión en curso. Esperá a que termine y volvé a simular.');
        }

        try {
            return $this->ejecutarAplicacion($request, $sentido, $anioLectivo, $divsObjetivo, $agregarAnios);
        } finally {
            $lock->release();
        }
    }

    private function ejecutarAplicacion(Request $request, string $sentido, int $anioLectivo, array $divsObjetivo, array $agregarAnios): JsonResponse
    {
        $maxAnio = $this->maxAnioActivo();
        if ($maxAnio <= 0) {
            ActivityLogger::log($request, 'Intento de promoción/reversión fallido: sin años activos', 'promocion.error');
            return response()->json([
                'ok'      => false,
                'message' => 'No hay años activos en la configuración de divisiones/cursos.',
            ], 422);
        }

        // Simulación previa para reportar en la respuesta qué se hizo.
        $sim = $this->buildSimulacion($sentido, $maxAnio, $agregarAnios, $divsObjetivo, $anioLectivo);

        $res = DB::transaction(function () use ($request, $sentido, $anioLectivo, $divsObjetivo, $agregarAnios, $maxAnio) {

            // 1) Si piden 7/8 (u otros), activarlos en parametro_divisiones y cursos
            foreach ($agregarAnios as $nuevo) {
                foreach ($divsObjetivo as $div) {
                    ParametroDivision::updateOrCreate(
                        ['anio' => $nuevo, 'division' => $div],
                        ['activo' => true]
                    );
                    Curso::updateOrCreate(
                        ['anio' => $nuevo, 'division' => $div],
                        ['activo' => true]
                    );
                }
            }

            // 2) Asegurar cursos activos para todas las combinaciones activas actuales
            $activos = ParametroDivision::where('activo', true)
                ->orderBy('anio')->orderBy('division')->get()->groupBy('anio');

            foreach ($activos as $anio => $rows) {
                foreach ($rows as $row) {
                    Curso::updateOrCreate(
                        ['anio' => (int) $anio, 'division' => (string) $row->division],
                        ['activo' => true]
                    );
                }
            }

            // Métricas de salida
            $res = [
                'ok'          => true,
                'accion'      => $sentido,
                'maxAnio'     => $maxAnio,
                'anioLectivo' => $anioLectivo,  // queda en el historial: sin esto no se sabe qué ciclo se movió
                'promovidos'  => 0,   // usados en up (subidos) y en down (bajados)
                'egresados'   => 0,   // solo up
                'corregidos'  => 0,   // defensivo / reingresados en down
                'message'     => '',
            ];

            if ($sentido === 'down') {
                // ====== REVERSIÓN ======
                // a) Bajar 1 año a todos los activos con anio > 1
                $res['promovidos'] = DB::table('alumnos')
                    ->whereNotIn('estado', Alumno::ESTADOS_BAJA)
                    ->where('anio', '>', 1)
                    ->update([
                        'anio'       => DB::raw('anio - 1'),
                        'updated_at' => now(),
                    ]);

                // b) Reingresar a los egresados del ciclo que se revierte, devolviéndolos
                //    al curso desde el que egresaron.
                // Se filtra por egreso_year: sin ese filtro reactivaría también a los
                // egresados de ciclos anteriores y les borraría su dato de egreso, que
                // después no hay cómo reconstruir.
                $egres = DB::table('alumnos')
                    ->select('id', 'egreso_anio', 'egreso_division')
                    ->where('estado', 'Egresado')
                    ->where('egreso_year', $anioLectivo)
                    ->get();

                foreach ($egres as $al) {
                    $res['corregidos'] += DB::table('alumnos')
                        ->where('id', $al->id)
                        ->update([
                            'estado'          => 'Activo',
                            'anio'            => (int) ($al->egreso_anio ?: $maxAnio),
                            'division'        => $this->divChar1($al->egreso_division) ?: '1',
                            'egreso_year'     => null,
                            'egreso_anio'     => null,
                            'egreso_division' => null,
                            'updated_at'      => now(),
                        ]) ? 1 : 0;
                }

                $res['message'] = ($res['promovidos'] + $res['corregidos']) > 0
                    ? 'Reversión aplicada correctamente.'
                    : 'No había cambios para aplicar (operación idempotente).';

                ActivityLogger::log($request, 'Reversión de año: ' . $res['message'], 'promocion.down', null, null, null, $this->resumenParaHistorial($res));
                return $res;
            }

            // ====== PROMOCIÓN (up) ======
            // IMPORTANTE: primero EGRESAR a los que ya están en el tope,
            // y recién después SUBIR a los que están por debajo del tope.

            // a) Egresar a quienes estén EXACTAMENTE en el tope activo actual
            $aEgresar = DB::table('alumnos')
                ->select('id', 'division')
                ->whereNotIn('estado', Alumno::ESTADOS_BAJA)
                ->where('anio', $maxAnio)
                ->get();

            foreach ($aEgresar as $al) {
                $res['egresados'] += DB::table('alumnos')
                    ->where('id', $al->id)
                    ->update([
                        'estado'          => 'Egresado',
                        'egreso_year'     => $anioLectivo,
                        'egreso_anio'     => $maxAnio,
                        'egreso_division' => $al->division,
                        'updated_at'      => now(),
                    ]) ? 1 : 0;
            }

            // b) Subir 1 año a todos los que estén por debajo del tope
            $res['promovidos'] = DB::table('alumnos')
                ->whereNotIn('estado', Alumno::ESTADOS_BAJA)
                ->whereBetween('anio', [1, $maxAnio - 1])
                ->update([
                    'anio'       => DB::raw('anio + 1'),
                    'updated_at' => now(),
                ]);

            // c) Defensivo: si quedara alguien > maxAnio por inconsistencias previas
            $res['corregidos'] = DB::table('alumnos')
                ->whereNotIn('estado', Alumno::ESTADOS_BAJA)
                ->where('anio', '>', $maxAnio)
                ->update([
                    'anio'       => $maxAnio,
                    'updated_at' => now(),
                ]);

            $res['message'] = ($res['promovidos'] + $res['egresados'] + $res['corregidos']) > 0
                ? 'Promoción aplicada correctamente.'
                : 'No había cambios para aplicar (operación idempotente).';

            ActivityLogger::log($request, 'Promoción de año: ' . $res['message'], 'promocion.up', null, null, null, $this->resumenParaHistorial($res));
            return $res;
        });

        $res['resultado'] = $sim;

        // Activar años nuevos cambia /divisiones; la caché de 24 h se sirve hasta
        // que se invalida acá.
        if ($agregarAnios) {
            \Illuminate\Support\Facades\Cache::forget('divisiones_index');
        }

        return response()->json($res);
    }

    // ==== helpers ====

    /**
     * El resultado de la promoción en palabras para el historial. La respuesta
     * que recibe el frontend mantiene sus claves técnicas; en la tarjeta tiene
     * que leerse "Alumnos que subieron de año: 118", no "promovidos: 118".
     */
    private function resumenParaHistorial(array $res): array
    {
        $bajando = ($res['accion'] ?? 'up') === 'down';
        $filas = [
            $bajando ? 'Alumnos que bajaron de año' : 'Alumnos que subieron de año' => $res['promovidos'] ?? 0,
            'Alumnos que egresaron' => $res['egresados'] ?? 0,
            $bajando ? 'Egresados que volvieron' : 'Alumnos corregidos' => $res['corregidos'] ?? 0,
            'Ciclo lectivo' => $res['anioLectivo'] ?? null,
            'Año máximo'    => $res['maxAnio'] ?? null,
        ];
        return array_filter($filas, fn ($v) => $v !== null && $v !== 0 && $v !== '');
    }

    /** Normaliza divisiones a 1..9 */
    private function normDivs(array $arr): array
    {
        $valid = ['1','2','3','4','5','6','7','8','9'];
        $out = array_values(array_unique(array_map(function ($d) {
            // Una división puede venir como lista en un pedido armado fuera de
            // la pantalla; leerla como texto sin guarda cortaba el preview de
            // la promoción entero con "Array to string conversion".
            return strtoupper(Texto::plano($d));
        }, $arr)));
        return array_values(array_filter($out, fn($d) => in_array($d, $valid, true)));
    }

    /**
     * División lista para escribir en columnas char(1) (alumnos.division, cursos.division).
     * egreso_division es char(2), así que puede traer valores de dos caracteres que
     * en char(1) troncan y, con sql_mode estricto, tiran el UPDATE entero.
     */
    private function divChar1(?string $d): string
    {
        $d = strtoupper(trim((string) $d));
        if ($d === '') return '';
        $letras = preg_match('/[A-ZÁÉÍÓÚÑ]/u', $d) ? $d : '';
        $digitos = preg_replace('/\D/', '', $d);
        // Prefiere el dígito (formato interno "1".."9"); si no hay, la primera letra.
        if ($digitos !== '') return substr($digitos, 0, 1);
        return mb_substr($letras, 0, 1, 'UTF-8') ?: substr($d, 0, 1);
    }
}

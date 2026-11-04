<?php

// Comando Artisan para realizar backfill de la tabla alumno_materias_estado
// a partir de las calificaciones existentes.

namespace App\Console\Commands;

use App\Dominios\Calificaciones\AlumnoCalificacion;
use App\Dominios\Calificaciones\AlumnoMateriaEstado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMateriasEstado extends Command
{
    protected $signature = 'app:backfill-materias-estado';
    protected $description = 'Backfill alumno_materias_estado from existing calificaciones';

    // Ejecuta el backfill: agrupa calificaciones por alumno/materia/ciclo y calcula estados y notas.
    public function handle()
    {
        $this->info('Backfilling alumno_materias_estado...');

        // Obtiene combinaciones unicas de alumno/materia/anio/division/ciclo para procesar.
        $combos = AlumnoCalificacion::query()
            ->select('alumno_id', 'materia_id', 'anio', 'division', 'ciclo_lectivo')
            ->distinct()
            ->orderBy('alumno_id')
            ->orderBy('materia_id')
            ->orderBy('ciclo_lectivo')
            ->get();

        // Barra de progreso para seguimiento visual.
        $bar = $this->output->createProgressBar($combos->count());
        $bar->start();

        $count = 0;

        foreach ($combos as $c) {
            $alumnoId = $c->alumno_id;
            $materiaId = $c->materia_id;
            $anio = $c->anio;
            $division = $c->division;
            $ciclo = $c->ciclo_lectivo;

            // Recupera notas de periodo 1 y 2 para esta combinacion.
            $notas = AlumnoCalificacion::where('alumno_id', $alumnoId)
                ->where('materia_id', $materiaId)
                ->where('anio', $anio)
                ->where('division', $division)
                ->where('ciclo_lectivo', $ciclo)
                ->whereIn('periodo', [1, 2])
                ->get(['valor', 'periodo', 'tipo_calificacion']);

            // Separa notas regulares por periodo y acumula las intensificaciones.
            $regP1 = []; $regP2 = [];
            $intensificaciones = [];

            foreach ($notas as $n) {
                $tipo = $n->tipo_calificacion ?: 'regular';
                $v = (float) $n->valor;
                if ($tipo === 'regular' && $n->periodo === 1) $regP1[] = $v;
                elseif ($tipo === 'regular' && $n->periodo === 2) $regP2[] = $v;
                else $intensificaciones[] = ['valor' => $v, 'tipo' => $tipo];
            }

            // Calcula promedio de cada periodo (nota >= 7 para aprobar).
            $promP1 = count($regP1) ? round(array_sum($regP1) / count($regP1), 2) : null;
            $promP2 = count($regP2) ? round(array_sum($regP2) / count($regP2), 2) : null;

            $p1Aprobado = $promP1 !== null && $promP1 >= 7;
            $p2Aprobado = $promP2 !== null && $promP2 >= 7;

            // Estado de cada periodo: pendiente, aprobado o desaprobado.
            $p1Estado = $promP1 === null ? 'pendiente' : ($p1Aprobado ? 'aprobado' : 'desaprobado');
            $p2Estado = $promP2 === null ? 'pendiente' : ($p2Aprobado ? 'aprobado' : 'desaprobado');

            // Inicializa variables para las distintas instancias de intensificacion.
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
                    $mejorInt = $v;
                }
            }

            // Logica de decision para nota final y estado final segun promedios e intensificaciones.
            $notaFinal = null;
            $estadoFinal = null;

            // Si aprobo ambos periodos, nota final es el promedio de ambos promedios.
            if ($p1Aprobado && $p2Aprobado && $promP1 !== null && $promP2 !== null) {
                $notaFinal = round(($promP1 + $promP2) / 2, 2);
                $estadoFinal = 'aprobada';
            } elseif ($aprobadoInt) {
                // Si alguna intensificacion fue aprobada (>=4), se considera aprobada.
                $notaFinal = $mejorInt;
                $estadoFinal = 'aprobada';
            // Determina el estado segun la ultima instancia de intensificacion cursada.
            } elseif ($promP1 !== null || $promP2 !== null || count($intensificaciones) > 0) {
                // Si rindio diciembre (pre o post), define si pasa a febrero o queda con CSA.
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

            AlumnoMateriaEstado::updateOrCreate(
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

            $count++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Backfill complete. {$count} records processed.");
    }
}

<?php

// Observer de AlumnoCalificacion. Recalcula automaticamente los promedios cuando se crean, actualizan o eliminan calificaciones.

namespace App\Dominios\Calificaciones;

use App\Dominios\Calificaciones\AlumnoCalificacion;
use App\Dominios\Calificaciones\AlumnoPromedio;

class AlumnoCalificacionObserver
{
    // Recalcula el promedio del alumno para la materia, anio, periodo y ciclo lectivo indicados.
    protected function recomputeFor(AlumnoCalificacion $row): void
    {
        // Si faltan datos obligatorios, no se puede recalcular.
        if (!$row->alumno_id || !$row->materia_id || !$row->anio || !$row->ciclo_lectivo) {
            return;
        }

        $division = $row->division ?: null;
        $periodo  = is_numeric($row->periodo) ? (int)$row->periodo : 0;

        // Solo notas regulares: intensificaciones, preliminares y finales
        // no promedian con los parciales (misma regla que recalcAndUpsertPromedios).
        $notas = AlumnoCalificacion::query()
            ->where('alumno_id', $row->alumno_id)
            ->where('materia_id', $row->materia_id)
            ->where('anio', $row->anio)
            ->where('division', $division)
            ->where('ciclo_lectivo', $row->ciclo_lectivo)
            ->where('periodo', $periodo)
            ->where(function ($q) {
                $q->whereNull('tipo_calificacion')
                  ->orWhere('tipo_calificacion', 'regular');
            })
            ->pluck('valor');

        $count = $notas->count();
        $prom  = $count > 0 ? round($notas->avg(), 2) : null;

        AlumnoPromedio::query()->updateOrCreate(
            [
                'alumno_id'     => $row->alumno_id,
                'materia_id'    => $row->materia_id,
                'anio'          => $row->anio,
                'division'      => $division,
                'ciclo_lectivo' => $row->ciclo_lectivo,
                'periodo'       => $periodo,
            ],
            [
                'promedio'    => $prom,
                'notas_count' => $count,
            ]
        );

        // Si no quedan notas, se elimina el registro de promedio (ya no tiene sentido mantenerlo).
        if ($count === 0) {
            AlumnoPromedio::query()
                ->where('alumno_id', $row->alumno_id)
                ->where('materia_id', $row->materia_id)
                ->where('anio', $row->anio)
                ->where('division', $division)
                ->where('ciclo_lectivo', $row->ciclo_lectivo)
                ->where('periodo', $periodo)
                ->delete();
        }
    }

    // Al crear una calificacion, recalcula el promedio correspondiente.
    public function created(AlumnoCalificacion $row): void { $this->recomputeFor($row); }
    // Al actualizar una calificacion, recalcula el promedio correspondiente.
    public function updated(AlumnoCalificacion $row): void { $this->recomputeFor($row); }
    // Al eliminar una calificacion, recalcula (o elimina) el promedio correspondiente.
    public function deleted(AlumnoCalificacion $row): void { $this->recomputeFor($row); }
}



<?php

namespace App\Dominios\Calificaciones;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Materia;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que almacena el promedio calculado de un alumno en una materia.
 *
 * Tabla: alumno_promedios
 * Guarda el valor del promedio y la cantidad de notas consideradas,
 * segmentado por anio, ciclo lectivo y periodo (0=anual, 1=primer, 2=segundo).
 */
class AlumnoPromedio extends Model
{
    protected $table = 'alumno_promedios';
    protected $guarded = ['id'];

    protected $casts = [
        'alumno_id'     => 'integer',
        'materia_id'    => 'integer',
        'anio'          => 'integer',
        'ciclo_lectivo' => 'integer',
        'periodo'       => 'integer', // 0..2 (0=Anual)
        'promedio'      => 'float',
        'notas_count'   => 'integer',
    ];

    /** Relacion: alumno al que pertenece el promedio */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class, 'alumno_id');
    }

    /** Relacion: materia del promedio (con valor por defecto) */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id')->withDefault();
    }

    /* Scopes */
    /** Scope: filtra por ID de alumno */
    public function scopeForAlumno($q, $alumnoId)  { return $alumnoId ? $q->where('alumno_id', $alumnoId) : $q; }
    /** Scope: filtra por ciclo lectivo */
    public function scopeForCiclo($q, $ciclo)      { return $ciclo ? $q->where('ciclo_lectivo', $ciclo) : $q; }
    /** Scope: filtra por periodo (0=anual, 1=primer, 2=segundo) */
    public function scopeForPeriodo($q, $periodo)  { return $periodo !== null ? $q->where('periodo', $periodo) : $q; }
}


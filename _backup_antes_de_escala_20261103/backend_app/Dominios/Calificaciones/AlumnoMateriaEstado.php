<?php

namespace App\Dominios\Calificaciones;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Materia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Modelo que representa el estado academico de un alumno en una materia.
 *
 * Tabla: alumno_materias_estado
 * Almacena la nota final y las notas de las distintas instancias de
 * intensificacion (1° cuatrimestre, diciembre, febrero y marzo-abril) para cada
 * materia y ciclo lectivo.
 */
class AlumnoMateriaEstado extends Model
{
    protected $table = 'alumno_materias_estado';

    protected $guarded = ['id'];

    protected $casts = [
        'alumno_id'                         => 'integer',
        'materia_id'                        => 'integer',
        'ciclo_lectivo'                     => 'integer',
        'anio'                              => 'integer',
        'nota_final'                        => 'decimal:2',
        'nota_intensificacion_febrero'      => 'decimal:2',
        'nota_intensificacion_1cuatri'     => 'decimal:2',
        'nota_intensificacion_diciembre'   => 'decimal:2',
        'nota_intensificacion_marzo_abril' => 'decimal:2',
    ];

    /** Relacion: alumno al que pertenece este estado */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class, 'alumno_id');
    }

    /** Relacion: materia asociada */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id');
    }

    /** Scope: filtra registros de un alumno especifico */
    public function scopeForAlumno(Builder $q, $alumnoId): Builder
    {
        return $q->where('alumno_id', $alumnoId);
    }

    /** Scope: filtra por ciclo lectivo */
    public function scopeForCiclo(Builder $q, ?int $ciclo): Builder
    {
        return $ciclo ? $q->where('ciclo_lectivo', $ciclo) : $q;
    }
}


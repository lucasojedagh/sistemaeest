<?php

namespace App\Dominios\Alumnos;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa un responsable (padre, madre, tutor) de un alumno.
 *
 * Tabla: alumno_responsables
 * Almacena datos de contacto, parentesco, si convive con el alumno
 * y si es el tutor legal.
 */
class AlumnoResponsable extends Model
{
    protected $table = 'alumno_responsables';

    protected $fillable = [
        'alumno_id','nombre','apellido','parentesco','convive','tutor_legal',
    ];

    protected $casts = [
        'convive'     => 'bool',
        'tutor_legal' => 'bool',
    ];

    /** Relacion inversa: alumno al que pertenece este responsable */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class);
    }
}


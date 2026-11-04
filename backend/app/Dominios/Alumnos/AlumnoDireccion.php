<?php

namespace App\Dominios\Alumnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modelo que representa una direccion asociada a un alumno.
 *
 * Tabla: alumno_direcciones
 * Permite registrar el domicilio del alumno y el nombre del titular
 * (a quien pertenece la direccion).
 */
class AlumnoDireccion extends Model
{
    use HasFactory;

    protected $table = 'alumno_direcciones';

    protected $fillable = ['alumno_id','titular','direccion'];

    /** Relacion inversa: alumno al que pertenece esta direccion */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class);
    }
}



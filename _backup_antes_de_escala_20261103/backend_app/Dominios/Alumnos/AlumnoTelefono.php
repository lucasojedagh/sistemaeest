<?php

namespace App\Dominios\Alumnos;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa un telefono de contacto asociado a un alumno.
 *
 * Tabla: alumno_telefonos
 * Permite registrar uno o mas numeros telefonicos por alumno,
 * indicando el titular de la linea.
 */
class AlumnoTelefono extends Model
{
    use HasFactory;

    protected $table = 'alumno_telefonos';
    public $timestamps = false; // <-- agregar

    protected $fillable = ['alumno_id','titular','telefono'];

    /** Relacion inversa: alumno al que pertenece este telefono */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class);
    }
}


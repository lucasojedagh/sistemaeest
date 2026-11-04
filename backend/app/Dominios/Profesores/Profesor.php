<?php

namespace App\Dominios\Profesores;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa un profesor/docente de la institucion.
 *
 * Tabla: profesores
 * Contiene datos personales, estado, si es suplente y observaciones.
 * La baja es borrado definitivo (sin papelera): el controlador libera
 * horarios y borra asignaciones/bajas en la misma transaccion.
 */
class Profesor extends Model
{
    protected $table = 'profesores';

    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'dni',
        'estado',
        'suplente',
        'observaciones',
        // ⚠️ Importante: NO incluir deleted_reason / deleted_by aquí
    ];

    protected $casts = [
        'suplente' => 'boolean',
    ];

    /** Relacion: asignaciones de materias que dicta este profesor */
    public function asignaciones()
    {
        return $this->hasMany(ProfesorAsignacion::class, 'profesor_id');
    }
}







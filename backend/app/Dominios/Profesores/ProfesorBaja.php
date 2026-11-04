<?php

namespace App\Dominios\Profesores;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que registra las bajas (desvinculaciones) de profesores.
 *
 * Tabla: profesor_bajas
 * Almacena el motivo de la baja y el usuario que la registro,
 * manteniendo un historial de las desvinculaciones docentes.
 */
class ProfesorBaja extends Model
{
    protected $table = 'profesor_bajas';

    protected $fillable = ['profesor_id', 'motivo', 'user_id'];

    /** Relacion inversa: profesor al que pertenece este registro de baja */
    public function profesor()
    {
        return $this->belongsTo(Profesor::class);
    }
}

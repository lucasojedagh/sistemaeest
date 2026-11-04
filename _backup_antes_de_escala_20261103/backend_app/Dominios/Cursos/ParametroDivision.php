<?php

namespace App\Dominios\Cursos;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa los parametros de configuracion de las divisiones.
 *
 * Tabla: parametro_divisiones
 * Define las divisiones disponibles por anio y si se encuentran activas,
 * funcionando como catalogo de referencia para otros modelos.
 */
class ParametroDivision extends Model
{
    protected $table = 'parametro_divisiones';

    protected $fillable = ['anio', 'division', 'activo'];

    protected $casts = [
        'anio'   => 'integer',
        'activo' => 'boolean',
    ];

    // Mantener las divisiones en mayúscula
    /** Evento saving: normaliza la division a mayuscula */
    protected static function booted(): void
    {
        static::saving(function (ParametroDivision $p) {
            if ($p->division !== null) {
                $p->division = strtoupper(trim((string)$p->division));
            }
        });
    }
}


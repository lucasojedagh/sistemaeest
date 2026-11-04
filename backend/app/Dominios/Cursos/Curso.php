<?php

namespace App\Dominios\Cursos;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa un curso (anio y division) dentro de la institucion.
 *
 * Tabla: cursos
 * Define la oferta academica: anio, division, turno, cupo maximo
 * y si se encuentra activo para el ciclo lectivo actual.
 */
class Curso extends Model
{
    protected $table = 'cursos';

    // Campos permitidos
    protected $fillable = ['anio', 'division', 'turno', 'cupo', 'activo'];

    // Casts útiles
    protected $casts = [
        'anio'   => 'integer',
        'cupo'   => 'integer',
        'activo' => 'boolean',
    ];

    // ---------- Helpers ----------
    /** Accesor: devuelve una clave unica con formato "anio-division" */
    public function getClaveAttribute(): string
    {
        return "{$this->anio}-{$this->division}";
    }

    // Normalización mínima: división en MAYÚSCULA siempre
    /** Evento saving: asegura que la division se almacene en mayuscula */
    protected static function booted(): void
    {
        static::saving(function (Curso $c) {
            if ($c->division !== null) {
                $c->division = strtoupper(trim((string)$c->division));
            }
        });
    }

    // ---------- Scopes ----------
    /** Scope: filtra cursos por anio */
    public function scopeAnio($q, int $anio)
    {
        return $q->where('anio', $anio);
    }

    /** Scope: filtra solo cursos activos */
    public function scopeActivos($q)
    {
        return $q->where('activo', true);
    }
}


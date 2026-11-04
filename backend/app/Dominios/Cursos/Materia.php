<?php

namespace App\Dominios\Cursos;

use App\Dominios\Profesores\ProfesorAsignacion;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa una materia (asignatura) de la institucion.
 *
 * Tabla: materias
 * Almacena el nombre de la materia, el anio al que pertenece y
 * si se encuentra activa para el ciclo lectivo.
 */
class Materia extends Model
{
    protected $table = 'materias';

    protected $fillable = [
        'anio',
        'nombre',
        // si existe en tu DB se usa, si no existe, no molesta
        'activo',
        'tipo',
        'especialidad',
    ];

    protected $casts = [
        'anio'   => 'integer',
        'activo' => 'boolean',
        'tipo' => 'string',
        'especialidad' => 'string',
    ];

    /* ========================= Relaciones ========================= */

    /** Relacion: asignaciones de profesores a esta materia */
    public function asignaciones()
    {
        return $this->hasMany(ProfesorAsignacion::class, 'materia_id');
    }

    /* ========================= Scopes ========================= */

    /** Scope: filtra materias de un anio especifico */
    public function scopeForAnio($q, int $anio)
    {
        return $q->where('anio', $anio);
    }

    /** Scope: filtra solo materias activas (defensivo si la columna no existe) */
    public function scopeActive($q)
    {
        // Solo filtra si la columna existe (defensivo)
        try {
            $columns = $this->getConnection()->getSchemaBuilder()->getColumnListing($this->getTable());
            if (in_array('activo', $columns, true)) {
                return $q->where('activo', true);
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return $q;
    }

    /** Scope: filtra materia por nombre exacto */
    public function scopeNamed($q, string $nombre)
    {
        return $q->where('nombre', $nombre);
    }

    /* ========================= Hooks ========================= */

    /** Evento saving: normaliza el nombre (sin espacios multiples) y valida que no este vacio */
    protected static function booted(): void
    {
        static::saving(function (Materia $m) {
            $m->nombre = preg_replace('/\s+/u', ' ', trim((string) $m->nombre));
            if ($m->nombre === '') {
                throw new \InvalidArgumentException('El nombre de la materia no puede estar vacío.');
            }
        });
    }
}


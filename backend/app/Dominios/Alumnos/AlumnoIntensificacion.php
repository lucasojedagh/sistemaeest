<?php

namespace App\Dominios\Alumnos;

use App\Dominios\Cursos\Materia;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa la inscripcion de un alumno a una materia de intensificacion.
 *
 * Tabla: alumno_intensificaciones
 * Las intensificaciones son espacios curriculares adicionales que un alumno
 * puede cursar para profundizar conocimientos en un area especifica.
 */
class AlumnoIntensificacion extends Model
{
    // Ajusta el nombre si tu tabla real es distinta
    protected $table = 'alumno_intensificaciones';

    protected $guarded = ['id'];

    protected $casts = [
        'alumno_id'  => 'integer',
        'anio'       => 'integer',
        'materia_id' => 'integer',
    ];

    /* ========================= Relaciones ========================= */

    /** Relacion: alumno que cursa la intensificacion */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class, 'alumno_id');
    }

    /**
     * Materia referenciada por FK (nuevo esquema).
     * withDefault() evita nulls si la FK quedó “huérfana”.
     */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id')->withDefault();
    }

    /* ========================= Accessors / Helpers ========================= */

    /**
     * Nombre “humano” de la materia.
     * - Prioriza el nombre desde la relación (FK).
     * - Fallback: usa columna legacy 'materia' (texto) si existiera.
     */
    public function getMateriaNombreAttribute(): ?string
    {
        if ($this->relationLoaded('materia') && $this->materia) {
            return $this->materia->nombre;
        }
        // Compatibilidad hacia atrás (si existe columna 'materia' tipo texto)
        return $this->attributes['materia'] ?? ($this->materia->nombre ?? null);
    }

    /**
     * Token de curso en formato ordinal (p. ej. "4to B").
     */
    public function getCursoAttribute(): string
    {
        if (!$this->anio) return '';
        return \App\Support\CourseLabel::label($this->anio, $this->division);
    }

    /* ========================= Scopes útiles ========================= */

    /** Scope: filtra por ID de alumno */
    public function scopeForAlumno($q, $alumnoId)
    {
        return is_null($alumnoId) ? $q : $q->where('alumno_id', $alumnoId);
    }

    /** Scope: filtra por anio academico */
    public function scopeForAnio($q, ?int $anio)
    {
        return $anio ? $q->where('anio', $anio) : $q;
    }

    /** Scope: filtra por division */
    public function scopeForDivision($q, ?string $division)
    {
        if ($division === null || $division === '') return $q;
        return $q->where('division', strtoupper($division));
    }

    /** Scope: filtra por ID de materia */
    public function scopeForMateriaId($q, ?int $materiaId)
    {
        return $materiaId ? $q->where('materia_id', $materiaId) : $q;
    }

    /* ========================= Normalización mínima ========================= */

    /** Evento saving: normaliza la division a mayuscula sin espacios extras */
    protected static function booted(): void
    {
        static::saving(function (AlumnoIntensificacion $r) {
            // Asegurar división consistente
            $r->division = $r->division ? strtoupper(trim($r->division)) : $r->division;
        });
    }
}



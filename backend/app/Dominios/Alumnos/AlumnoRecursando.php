<?php

namespace App\Dominios\Alumnos;

use App\Dominios\Cursos\Materia;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa una materia que un alumno esta recursando.
 *
 * Tabla: alumno_recursandos
 * Registra las materias que el alumno debe cursar nuevamente por
 * haberlas desaprobado en ciclos lectivos anteriores.
 */
class AlumnoRecursando extends Model
{
    // En tu dump aparece como 'alumno_recursandos' (plural). Si tu tabla fuese singular, ajusta aquí.
    protected $table = 'alumno_recursandos';
    protected $guarded = ['id'];

    protected $casts = [
        'alumno_id'  => 'integer',
        'anio'       => 'integer',
        'materia_id' => 'integer',
    ];

    /* ========================= Relaciones ========================= */

    /** Relacion: alumno que esta recursando */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class, 'alumno_id');
    }

    /** Relacion: materia que se esta recursando */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id')->withDefault();
    }

    /* ========================= Accessors ========================= */

    /** Accesor: nombre de la materia desde la relacion o columna legacy */
    public function getMateriaNombreAttribute(): ?string
    {
        if ($this->relationLoaded('materia') && $this->materia) {
            return $this->materia->nombre;
        }
        return $this->attributes['materia'] ?? ($this->materia->nombre ?? null);
    }

    /** Accesor: devuelve el curso en formato ordinal (ej. "4to B") */
    public function getCursoAttribute(): string
    {
        if (!$this->anio) return '';
        return \App\Support\CourseLabel::label($this->anio, $this->division);
    }

    /* ========================= Scopes ========================= */

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

    /* ========================= Normalización ========================= */

    /** Evento saving: normaliza la division a mayuscula */
    protected static function booted(): void
    {
        static::saving(function (AlumnoRecursando $r) {
            $r->division = $r->division ? strtoupper(trim($r->division)) : $r->division;
        });
    }
}


<?php

namespace App\Dominios\Profesores;

use App\Dominios\Cursos\Materia;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa la asignacion de un profesor a una materia y curso.
 *
 * Tabla: profesor_asignaciones
 * Vincula un profesor con una materia en un anio, division y grupo especifico.
 * Sin papelera: se borran junto al profesor (FK cascade + borrado explícito).
 */
class ProfesorAsignacion extends Model
{
    protected $table = 'profesor_asignaciones';

    /**
     * Campos permitidos para asignación masiva.
     * (Si preferís, podés usar $guarded = []; y validar en el controlador).
     */
    protected $fillable = [
        'profesor_id',
        'materia_id',
        'anio',
        'division',
        'grupo', // Agregado para soportar asignacion a subgrupos (A/B) dentro de una misma division
    ];

    protected $casts = [
        'profesor_id' => 'integer',
        'materia_id'  => 'integer',
        'anio'        => 'integer',
    ];

    protected $dates = ['deleted_at'];

    /* ========================= Relaciones ========================= */

    /** Relacion inversa: profesor asignado */
    public function profesor()
    {
        return $this->belongsTo(Profesor::class, 'profesor_id');
    }

    /**
     * Materia referenciada por FK (nuevo esquema).
     * withDefault() evita nulls si hubiese referencias huérfanas.
     */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id')->withDefault();
    }

    /* ========================= Accessors / Helpers ========================= */

    /**
     * Nombre legible de la materia. Prioriza relación por FK.
     */
    public function getMateriaNombreAttribute(): ?string
    {
        if ($this->relationLoaded('materia') && $this->materia && $this->materia->exists) {
            return $this->materia->nombre;
        }
        // fallback ultra defensivo si existiera un campo legacy 'materia' (texto)
        return $this->attributes['materia'] ?? null;
    }

    /**
     * Curso en formato ordinal (p. ej. "4to B").
     */
    public function getCursoAttribute(): string
    {
        if (!$this->anio) return '';
        return \App\Support\CourseLabel::label($this->anio, $this->division);
    }

    /* ========================= Scopes útiles ========================= */

    /** Scope: filtra por ID de profesor */
    public function scopeForProfesor($q, $profesorId)
    {
        return is_null($profesorId) ? $q : $q->where('profesor_id', $profesorId);
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

    /**
     * Filtra asignaciones cuya materia esté activa.
     * Requiere columna 'activo' en materias (boolean).
     */
    public function scopeWithActiveMateria($q)
    {
        return $q->whereHas('materia', function ($m) {
            $m->where('activo', true);
        });
    }

    /* ========================= Normalización mínima ========================= */

    /** Evento saving: normaliza la division a mayuscula */
    protected static function booted(): void
    {
        static::saving(function (ProfesorAsignacion $a) {
            // División en mayúscula, sin espacios extra
            $a->division = $a->division ? strtoupper(trim($a->division)) : $a->division;
        });
    }
}



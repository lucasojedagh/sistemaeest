<?php

namespace App\Dominios\Calificaciones;

use App\Dominios\Alumnos\Alumno;
use App\Dominios\Cursos\Materia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Modelo que representa una calificacion individual de un alumno en una materia.
 *
 * Tabla: alumno_calificaciones
 * Almacena notas con referencia al periodo (anual, 1er o 2do periodo),
 * ciclo lectivo, y fecha de registro.
 */
class AlumnoCalificacion extends Model
{
    protected $table = 'alumno_calificaciones';
    protected $guarded = ['id'];

    protected $casts = [
        'alumno_id'     => 'integer',
        'materia_id'    => 'integer',
        'anio'          => 'integer',
        'ciclo_lectivo' => 'integer', // p.ej. 2025
        'periodo'       => 'integer', // 0=anual; 1..2
        'valor'         => 'string',
        'fecha'         => 'date',
        'detalle'       => 'json',
        'descripcion'   => 'string',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    /* ========================= Relaciones ========================= */

    /** Relacion: alumno al que pertenece la calificacion */
    public function alumno()
    {
        return $this->belongsTo(Alumno::class, 'alumno_id');
    }

    /** Relacion: materia evaluada (con valor por defecto para evitar nulos) */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id')->withDefault();
    }

    /* ========================= Accessors / Helpers ========================= */

    /** Accesor: nombre de la materia desde la relacion o columna legacy */
    public function getMateriaNombreAttribute(): ?string
    {
        if ($this->relationLoaded('materia') && $this->materia && $this->materia->exists) {
            return $this->materia->nombre;
        }
        // fallback “legacy” si existe columna de texto 'materia'
        return $this->attributes['materia'] ?? null;
    }

    /** Accesor: devuelve el curso en formato ordinal (ej. "4to B") */
    public function getCursoAttribute(): string
    {
        if (!$this->anio) return '';
        return \App\Support\CourseLabel::label($this->anio, $this->division);
    }

    /** Accesor: etiqueta legible del periodo (Anual, 1 Periodo, 2 Periodo) */
    public function getPeriodoLabelAttribute(): string
    {
        return static::periodoLabel($this->periodo);
    }

    /** Metodo estatico: convierte el numero de periodo en una etiqueta */
    public static function periodoLabel(?int $p): string
    {
        if ($p === 0 || $p === null) return 'Anual';
        if ($p === 1) return '1° Período';
        if ($p === 2) return '2° Período';
        return 'Período';
    }

    /* ========================= Scopes ========================= */

    /** Scope: filtra por ID de alumno */
    public function scopeForAlumno(Builder $q, $alumnoId): Builder
    {
        return is_null($alumnoId) ? $q : $q->where('alumno_id', $alumnoId);
    }

    /** Scope: filtra por anio academico */
    public function scopeForAnio(Builder $q, ?int $anio): Builder
    {
        return $anio ? $q->where('anio', $anio) : $q;
    }

    /** Scope: filtra por division (letra) */
    public function scopeForDivision(Builder $q, ?string $division): Builder
    {
        if ($division === null || $division === '') return $q;
        return $q->where('division', strtoupper($division));
    }

    /** Scope: filtra por ID de materia */
    public function scopeForMateriaId(Builder $q, ?int $materiaId): Builder
    {
        return $materiaId ? $q->where('materia_id', $materiaId) : $q;
    }

    /** Scope: filtra por ciclo lectivo (anio calendario) */
    public function scopeForCiclo(Builder $q, ?int $ciclo): Builder
    {
        return $ciclo ? $q->where('ciclo_lectivo', $ciclo) : $q;
    }

    /** Scope: filtra por periodo (0=anual, 1=primer, 2=segundo) */
    public function scopeForPeriodo(Builder $q, ?int $periodo): Builder
    {
        return is_null($periodo) ? $q : $q->where('periodo', $periodo);
    }

    /** Scope: filtra calificaciones entre dos fechas */
    public function scopeBetweenDates(Builder $q, ?string $from, ?string $to): Builder
    {
        if ($from) $q->whereDate('fecha', '>=', $from);
        if ($to)   $q->whereDate('fecha', '<=', $to);
        return $q;
    }

    /** Scope: ordena por fecha ascendente e ID */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('fecha')->orderBy('id');
    }

    /** Scope: filtra por el ciclo lectivo actual (anio en curso) */
    public function scopeCicloActual(Builder $q): Builder
    {
        return $q->where('ciclo_lectivo', Carbon::now()->year);
    }

    /* ========================= Normalización mínima ========================= */

    /** Evento saving: normaliza division, asigna fecha y ciclo lectivo por defecto */
    protected static function booted(): void
    {
        static::saving(function (AlumnoCalificacion $r) {
            $r->division = $r->division ? strtoupper(trim($r->division)) : $r->division;

            if (empty($r->fecha)) {
                $r->fecha = Carbon::today();
            }
            if (empty($r->ciclo_lectivo) && !empty($r->fecha)) {
                $r->ciclo_lectivo = Carbon::parse($r->fecha)->year;
            }
        });
    }
}


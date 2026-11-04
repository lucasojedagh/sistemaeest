<?php

namespace App\Dominios\Alumnos;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa una sobreescritura (override) del horario de un alumno.
 *
 * Tabla: alumno_horario_overrides
 * Permite personalizar el horario de un alumno en un dia y bloque especifico,
 * indicando la materia, profesor y tipo de marcado (normal, recursando, intensificacion).
 */
class AlumnoHorarioOverride extends Model
{
    protected $table = 'alumno_horario_overrides';

    protected $fillable = [
        'alumno_id', 'dia', 'bloque',
        'materia_id', 'materia_nombre',
        'profesor_id', 'profesor_nombre',
        'marcado',
        'created_at', 'updated_at', // quita estas 2 si no tienes timestamps
    ];

    // Si tu tabla NO tiene created_at/updated_at, cambia a false y
    // quita las columnas de $fillable:
    public $timestamps = true;

    protected $casts = [
        'alumno_id'       => 'integer',
        'dia'             => 'integer',
        'bloque'          => 'integer',
        'materia_id'      => 'integer',
        'profesor_id'     => 'integer',
        'materia_nombre'  => 'string',
        'profesor_nombre' => 'string',
        'marcado'         => 'string',
    ];

    // Valores válidos para "marcado"
    public const MARCADO_NORMAL          = 'normal';
    public const MARCADO_RECURSANDO      = 'recursando';
    public const MARCADO_INTENSIFICACION = 'intensificacion';

    /* -------------------- Relaciones (opcionales) -------------------- */
    /** Relacion: alumno asociado al override */
    public function alumno()
    {
        return $this->belongsTo(\App\Dominios\Alumnos\Alumno::class, 'alumno_id');
    }

    /** Relacion: materia del override */
    public function materia()
    {
        return $this->belongsTo(\App\Dominios\Cursos\Materia::class, 'materia_id');
    }

    /** Relacion: profesor a cargo en este override */
    public function profesor()
    {
        return $this->belongsTo(\App\Dominios\Profesores\Profesor::class, 'profesor_id');
    }

    /* ------------------------ Scopes útiles ------------------------- */
    /** Scope: busca un override por alumno, dia y bloque */
    public function scopeForCell($q, int $alumnoId, int $dia, int $bloque)
    {
        return $q->where('alumno_id', $alumnoId)
                 ->where('dia', $dia)
                 ->where('bloque', $bloque);
    }

    /* ------------- Accessors de conveniencia (opcionales) ---------- */
    /** Accesor: extrae el apellido del profesor desde el nombre completo */
    public function getProfesorApellidoAttribute(): string
    {
        $full = trim((string) $this->profesor_nombre);
        if ($full === '') return '';
        $parts = preg_split('/\s+/', $full) ?: [];
        if (count($parts) <= 1) return $full;

        $joiners = ['de','del','da','das','do','dos','di','du','van','von','la','las','los','y'];
        $last = $parts[count($parts) - 1];
        $pen  = $parts[count($parts) - 2] ?? '';

        if (in_array(mb_strtolower($pen), $joiners, true)) {
            return $pen . ' ' . $last;
        }
        return $last;
    }
}


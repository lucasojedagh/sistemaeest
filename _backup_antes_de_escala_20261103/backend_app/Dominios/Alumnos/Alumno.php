<?php

namespace App\Dominios\Alumnos;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo principal que representa a un alumno de la institucion.
 *
 * Tabla: alumnos
 * Contiene los datos personales, academicos (anio, division, grupo),
 * estado (Activo/Egresado), indicadores de inclusion (PPI, AE) e
 * informacion de egreso.
 */
class Alumno extends Model
{
    use HasFactory;

    protected $table = 'alumnos';

    protected $fillable = [
        'nombre',
        'apellido',
        'genero',              // F | M | O
        'dni',
        'fecha_nacimiento',
        'anio',
        'division',
        'grupo',
        'estado',              // Activo | Egresado (u otros estados)
        'observaciones',

        // banderas
        'tiene_ppi',           // Proyecto Pedagógico de Inclusión
        'tiene_ae',            // Acompañante Externo

        // especialidad (solo 4to año en adelante)
        'especialidad',        // informatica | mmo | electromecanica | quimica

        // info de egreso
        'egreso_year',         // YYYY
        'egreso_anio',         // 6 / 7 / 8
        'egreso_division',     // A, B, C...
    ];

    protected $attributes = [
        'estado'     => 'Activo',
        'tiene_ppi'  => false,
        'tiene_ae'   => false,
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'egreso_year'      => 'integer',
        'egreso_anio'      => 'integer',
        'tiene_ppi'        => 'bool',
        'tiene_ae'         => 'bool',
    ];

    /* -------------------------- Relaciones -------------------------- */

    /** Relacion: direcciones asociadas al alumno */
    public function direcciones()
    {
        return $this->hasMany(AlumnoDireccion::class);
    }

    /** Relacion: telefonos de contacto del alumno */
    public function telefonos()
    {
        return $this->hasMany(AlumnoTelefono::class);
    }

    /** Relacion: materias que el alumno esta recursando */
    public function recursandos()
    {
        return $this->hasMany(AlumnoRecursando::class);
    }

    /** Relacion: materias de intensificacion del alumno */
    public function intensificaciones()
    {
        return $this->hasMany(AlumnoIntensificacion::class);
    }

    /** Relacion: responsables (padres/tutores) del alumno */
    public function responsables()
    {
        return $this->hasMany(AlumnoResponsable::class);
    }

    /* --------------------------- Accessors -------------------------- */

    /** Accesor: devuelve una etiqueta legible del curso (ej. "4to B") */
    public function getAnioLabelAttribute(): string
    {
        if (!$this->anio) return '';
        return \App\Support\CourseLabel::label($this->anio, $this->division);
    }

    /** Mapa de especialidades válidas con su etiqueta legible */
    public const ESPECIALIDADES = [
        'informatica'     => 'Técnico en Informática P. y P.',
        'mmo'             => 'Maestro Mayor de Obras',
        'electromecanica' => 'Técnico en Electromecánica',
        'quimica'         => 'Técnico Químico',
    ];

    /** Accesor: etiqueta legible de la especialidad */
    public function getEspecialidadLabelAttribute(): ?string
    {
        if (!$this->especialidad) return null;
        return self::ESPECIALIDADES[$this->especialidad] ?? $this->especialidad;
    }

    /* ---------------------------- Mutators -------------------------- */

    /**
     * Mutator: normaliza el genero a una unica letra (F, M, O).
     * Segunda cerradura: cualquier otro valor queda en null en vez de
     * guardarse tal cual (la validación ya solo admite F/M/O al crear/editar).
     */
    public function setGeneroAttribute($value): void
    {
        $v = strtoupper(trim((string) $value));
        $map = ['FEMENINO' => 'F', 'MASCULINO' => 'M', 'OTROS' => 'O', 'OTRO' => 'O'];
        $letra = $map[$v] ?? $v;
        $this->attributes['genero'] = in_array($letra, ['F', 'M', 'O'], true) ? $letra : null;
    }

    /** Mutator: normaliza la division a mayuscula y un solo caracter */
    public function setDivisionAttribute($value): void
    {
        $v = strtoupper((string) $value);
        $this->attributes['division'] = $v !== '' ? substr($v, 0, 1) : null;
    }

    /** Mutator: normaliza la especialidad a una clave conocida (o null) */
    public function setEspecialidadAttribute($value): void
    {
        $v = strtolower(trim((string) ($value ?? '')));
        $this->attributes['especialidad'] = $v !== '' && isset(self::ESPECIALIDADES[$v]) ? $v : null;
    }

    /** Mutator: limpia espacios al inicio/fin del nombre */
    public function setNombreAttribute($value): void
    {
        $this->attributes['nombre'] = is_string($value) ? trim($value) : $value;
    }

    /** Mutator: limpia espacios al inicio/fin del apellido */
    public function setApellidoAttribute($value): void
    {
        $this->attributes['apellido'] = is_string($value) ? trim($value) : $value;
    }

    /* ----------------------------- Scopes --------------------------- */

    /** Scope: filita solo alumnos activos (no egresados) */
    public function scopeActivos($q)
    {
        return $q->where('estado', '!=', 'Egresado');
    }

    /** Scope: filtra solo alumnos egresados */
    public function scopeEgresados($q)
    {
        return $q->where('estado', 'Egresado');
    }

    /** Scope: filtra alumnos por anio y division opcional */
    public function scopeDelAnio($q, int $anio, ?string $division = null)
    {
        $q->where('anio', $anio);
        if ($division) $q->where('division', strtoupper($division));
        return $q;
    }
}


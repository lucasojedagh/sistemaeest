<?php

namespace App\Dominios\Cursos;

use App\Dominios\Profesores\Profesor;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa una celda individual del horario escolar.
 *
 * Tabla: horario_celdas
 * Cada celda vincula un curso, un dia de la semana, un horario (slot_key/bloque),
 * una materia y un profesor. Define la grilla horaria completa de la institucion.
 */
class HorarioCelda extends Model
{
    /**
     * Tabla donde se guardan las celdas del horario del curso/división.
     */
    protected $table = 'horario_celdas';

    /**
     * Si preferís whitelisting, cambiá a $fillable explícito.
     */
    protected $guarded = ['id'];

    /**
     * Casts relevantes.
     */
    protected $casts = [
        'curso_id'    => 'integer',   // FK a cursos
        'dia'         => 'integer',   // 1..5 (Lun..Vie)
        'materia_id'  => 'integer',
        'profesor_id' => 'integer',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    /* ============================================================
     |  Helpers de slot (índice <-> slot_key)
     |  h1 => 0, h2 => 1, ...
     * ============================================================ */
    public static function slotKeyToIdx(?string $slot): ?int
    {
        if (!$slot) return null;
        if (!preg_match('/^h(\d+)$/i', $slot, $m)) return null;
        $i = (int) $m[1];
        return $i > 0 ? $i - 1 : null; // 0-based
    }

    public static function idxToSlotKey(?int $i): ?string
    {
        if ($i === null) return null;
        return 'h' . ($i + 1);
    }

    /* ============================================================
     |  Mutators (normalización al guardar)
     * ============================================================ */

    /**
     * Normaliza el slot_key a formato "hN".
     */
    public function setSlotKeyAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['slot_key'] = null;
            return;
        }
        // Acepta "h3", "H3", "3", 3
        if (is_numeric($value)) {
            $this->attributes['slot_key'] = self::idxToSlotKey((int)$value);
            return;
        }
        if (preg_match('/^h(\d+)$/i', (string)$value, $m)) {
            $n = max(1, (int)$m[1]);
            $this->attributes['slot_key'] = 'h' . $n;
            return;
        }
        // Limpieza por si viene cualquier cosa tipo "bloque_4"
        $n = (int) preg_replace('/\D+/', '', (string)$value);
        $this->attributes['slot_key'] = $n > 0 ? ('h' . $n) : null;
    }

    /**
     * Acepta "bloque" (0-based) por compatibilidad y rellena slot_key si viene vacío.
     * NOTA: horario_celdas NO tiene columna 'bloque' (solo slot_key), por eso
     * no se persiste: solo se usa para inferir slot_key.
     */
    public function setBloqueAttribute($value): void
    {
        // Si no mandaron slot_key, lo infiero a partir del bloque
        if (empty($this->attributes['slot_key'])) {
            $this->attributes['slot_key'] = self::idxToSlotKey(
                is_numeric($value) ? (int)$value : null
            );
        }
    }

    /* ============================================================
     |  Relaciones
     * ============================================================ */

    /**
     * Curso al que pertenece la celda (anio/división).
     * Ajustá el namespace de Curso si difiere.
     */
    public function curso()
    {
        return $this->belongsTo(Curso::class, 'curso_id');
    }

    /**
     * Materia vinculada por FK.
     */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id')->withDefault();
    }

    /** Profesor (opcional). */
    public function profesor()
    {
        return $this->belongsTo(Profesor::class, 'profesor_id');
    }

    /* ============================================================
     |  Accessors / Helpers
     * ============================================================ */

    /**
     * Nombre humano de la materia (por relación).
     * Fallback a columna legacy 'materia' si existiera.
     */
    public function getMateriaNombreAttribute(): ?string
    {
        if ($this->relationLoaded('materia') && $this->materia) {
            return $this->materia->nombre;
        }
        // Fallback legacy si tenés una columna texto 'materia'
        return $this->attributes['materia'] ?? ($this->materia->nombre ?? null);
    }

    /**
     * Devuelve el curso en formato ordinal (p.ej. "3ro C") a partir de la relación curso.
     */
    public function getCursoAttribute(): string
    {
        $this->loadMissing('curso');
        $anio = $this->curso->anio ?? null;
        $div  = $this->curso->division ?? null;
        if (!$anio) return '';
        return \App\Support\CourseLabel::label($anio, $div);
    }

    /**
     * Accesor auxiliar: índice (0-based) equivalente al slot_key.
     */
    public function getBloqueIndexAttribute(): ?int
    {
        $slot = $this->attributes['slot_key'] ?? null;
        return self::slotKeyToIdx($slot);
    }

    /* ============================================================
     |  Scopes útiles
     * ============================================================ */

    public function scopeForCurso($q, int $cursoId)
    {
        return $q->where('curso_id', $cursoId);
    }

    public function scopeForDia($q, int $dia)
    {
        return $q->where('dia', $dia); // 1..5
    }

    public function scopeForSlot($q, string $slotKey)
    {
        // Acepta "h3" o 2 (índice)
        if (is_numeric($slotKey)) {
            $slotKey = self::idxToSlotKey((int)$slotKey);
        }
        return $q->where('slot_key', $slotKey);
    }

    public function scopeForMateriaId($q, ?int $materiaId)
    {
        return $materiaId ? $q->where('materia_id', $materiaId) : $q;
    }

    public function scopeForProfesorId($q, ?int $profesorId)
    {
        return $profesorId ? $q->where('profesor_id', $profesorId) : $q;
    }
}


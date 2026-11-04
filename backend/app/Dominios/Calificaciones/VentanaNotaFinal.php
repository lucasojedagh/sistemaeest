<?php

namespace App\Dominios\Calificaciones;

use Illuminate\Database\Eloquent\Model;

class VentanaNotaFinal extends Model
{
    protected $table = 'ventana_nota_final';

    protected $fillable = [
        'fecha_inicio',
        'fecha_fin',
        'ciclo_lectivo',
        'alcance',
        'created_by',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin'    => 'datetime',
    ];

    // La ventana es de fila unica: devuelve la configuracion actual (la mas reciente).
    public static function actual(): ?self
    {
        return self::orderBy('id', 'desc')->first();
    }

    // Indica si la ventana se encuentra abierta en este instante.
    public function estaAbierta(): bool
    {
        if (!$this->fecha_inicio || !$this->fecha_fin) {
            return false;
        }
        $now = now();
        return $now->between($this->fecha_inicio, $this->fecha_fin);
    }

    // Qué notas habilita: 'p1' (1er cuat.), 'p2' (2do cuat.) o 'ambos'.
    // Sin valor (filas viejas) se asume 'ambos' para no romper nada.
    public function alcanceNormalizado(): string
    {
        $a = strtolower(trim((string) ($this->alcance ?? 'ambos')));
        return in_array($a, ['p1', 'p2', 'ambos'], true) ? $a : 'ambos';
    }

    // ¿Permite cargar la nota final del período indicado (1 o 2)?
    public function permitePeriodo(int $periodo): bool
    {
        if (!$this->estaAbierta()) {
            return false;
        }
        $a = $this->alcanceNormalizado();
        if ($a === 'ambos') {
            return in_array($periodo, [1, 2], true);
        }
        if ($a === 'p1') {
            return $periodo === 1;
        }
        return $periodo === 2;
    }
}


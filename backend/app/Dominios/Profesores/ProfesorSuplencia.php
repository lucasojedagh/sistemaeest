<?php

namespace App\Dominios\Profesores;

use App\Dominios\Cursos\Materia;
use Illuminate\Database\Eloquent\Model;

/**
 * Un eslabón de la cadena de suplencias: titular_id es reemplazado por
 * suplente_id en (anio, division, materia_id), con un motivo opcional.
 */
class ProfesorSuplencia extends Model
{
    protected $table = 'profesor_suplencias';

    protected $fillable = [
        'titular_id',
        'suplente_id',
        'anio',
        'division',
        'materia_id',
        'motivo',
    ];

    protected $casts = [
        'titular_id'  => 'integer',
        'suplente_id' => 'integer',
        'anio'        => 'integer',
        'materia_id'  => 'integer',
    ];

    /** Quién es reemplazado en este eslabón. */
    public function titular()
    {
        return $this->belongsTo(Profesor::class, 'titular_id');
    }

    /** Quién reemplaza en este eslabón. */
    public function suplente()
    {
        return $this->belongsTo(Profesor::class, 'suplente_id');
    }

    /** Materia que se cubre en este eslabón. */
    public function materia()
    {
        return $this->belongsTo(Materia::class, 'materia_id');
    }
}

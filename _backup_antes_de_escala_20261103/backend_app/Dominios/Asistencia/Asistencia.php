<?php

namespace App\Dominios\Asistencia;

use App\Dominios\Alumnos\Alumno;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asistencia extends Model
{
    protected $table = 'asistencias';

    protected $fillable = [
        'clase_id',
        'alumno_id',
        'estado',
        'anotacion',
    ];

    public function clase(): BelongsTo
    {
        return $this->belongsTo(Clase::class, 'clase_id');
    }

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Alumno::class, 'alumno_id');
    }

    public function justificacion(): HasOne
    {
        return $this->hasOne(AsistenciaJustificacion::class, 'asistencia_id');
    }
}
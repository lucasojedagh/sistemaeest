<?php

namespace App\Dominios\Asistencia;

use App\Dominios\Cursos\Curso;
use App\Dominios\Usuarios\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clase extends Model
{
    protected $table = 'clases';

    protected $fillable = [
        'curso_id',
        'fecha',
        'numero_clase',
        'hora_inicio',
        'hora_fin',
        'responsable_id',
        'tipo_responsable',
        'estado',
        'comentario',
    ];

    protected $casts = [
        'fecha'        => 'date',
        'numero_clase' => 'integer',
    ];

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class, 'curso_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'clase_id');
    }
}

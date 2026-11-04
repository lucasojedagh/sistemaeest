<?php

namespace App\Dominios\Asistencia;

use App\Dominios\Documentos\Documento;
use App\Dominios\Usuarios\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsistenciaJustificacion extends Model
{
    protected $table = 'asistencia_justificaciones';

    protected $fillable = [
        'asistencia_id',
        'motivo',
        'observacion',
        'documento_id',
        'estado',
        'registrado_por',
        'aprobado_por',
    ];

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
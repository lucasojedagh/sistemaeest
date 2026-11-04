<?php

namespace App\Dominios\Sistema;

use App\Dominios\Usuarios\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa el registro de auditoria (log de actividades) del sistema.
 *
 * Tabla: activity_logs
 * Almacena un historial de cambios realizados sobre los modelos del sistema,
 * incluyendo los valores anteriores y nuevos de cada modificacion.
 */
class ActivityLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'activity_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'user_id'     => 'integer',
        'old_values'  => 'json',
        'new_values'  => 'json',
        'detalles'    => 'string',
        'created_at'  => 'datetime',
    ];

    /** Relacion: usuario que realizo la accion */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Scope: ordena los registros del mas reciente al mas antiguo */
    public function scopeRecent($q)
    {
        return $q->orderBy('created_at', 'desc');
    }

    /** Scope: filtra por tipo de accion (create, update, delete, etc.) */
    public function scopeOfType($q, $type)
    {
        return $type ? $q->where('action_type', $type) : $q;
    }
}


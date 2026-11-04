<?php

namespace App\Dominios\Usuarios;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que asigna permisos de acceso a un usuario sobre un anio y division.
 *
 * Tabla: user_anio_division
 * Define que anios y divisiones puede visualizar o gestionar cada usuario
 * del sistema, funcionando como un mecanismo de autorizacion por alcance.
 */
class UserAnioDivision extends Model
{
    protected $table = 'user_anio_division';

    protected $fillable = [
        'user_id',
        'anio',
        'division',
    ];

    protected $casts = [
        'anio' => 'integer',
    ];

    /** Relacion inversa: usuario al que pertenece este permiso */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Evento saving: normaliza la division a mayuscula */
    protected static function booted(): void
    {
        static::saving(function (UserAnioDivision $m) {
            $m->division = $m->division ? strtoupper(trim($m->division)) : $m->division;
        });
    }
}

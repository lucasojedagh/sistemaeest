<?php

namespace App\Dominios\Usuarios;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuracion de un rol: su rango (nivel) y qué puede hacer.
 *
 * Tabla: roles_config. Una fila por rol personalizado, y una fila por rol de
 * sistema al que se le quitan permisos (negados).
 */
class RolConfig extends Model
{
    protected $table = 'roles_config';

    protected $fillable = ['rol', 'nombre', 'nivel', 'tipo', 'permitidos', 'negados', 'alcance', 'modulos', 'descripcion'];

    protected $casts = [
        'nivel'      => 'integer',
        'permitidos' => 'array',
        'negados'    => 'array',
        'alcance'    => 'array',
        'modulos'    => 'array',
    ];

    /** Registro en memoria: se lee una sola vez por request. */
    private static ?array $registry = null;

    /**
     * Todos los roles configurables indexados por su clave.
     * Devuelve [] si la tabla aún no existe (migración pendiente) o si no hay BD.
     */
    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        try {
            $filas = static::query()->get();
        } catch (\Throwable) {
            return self::$registry = [];
        }

        $out = [];
        foreach ($filas as $f) {
            $out[$f->rol] = [
                'rol'         => $f->rol,
                'nombre'      => $f->nombre,
                'nivel'       => (int) $f->nivel,
                'tipo'        => $f->tipo,
                'permitidos'  => $f->permitidos ?? [],
                'negados'     => $f->negados ?? [],
                'alcance'     => $f->alcance ?? [],
                'modulos'     => $f->modulos ?? null,
                'descripcion' => $f->descripcion,
            ];
        }

        return self::$registry = $out;
    }

    /** Descarta el registro en memoria (tras crear, editar o borrar un rol). */
    public static function olvidarRegistro(): void
    {
        self::$registry = null;
    }
}

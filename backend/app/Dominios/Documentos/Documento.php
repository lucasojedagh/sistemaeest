<?php

namespace App\Dominios\Documentos;

use App\Dominios\Usuarios\User;
use Illuminate\Database\Eloquent\Model;

class Documento extends Model
{
    protected $table = 'documentos';

    protected $fillable = [
        'user_id',
        'nombre',
        'nombre_archivo',
        'ruta',
        'mime',
        'tamano',
        'roles',
    ];

    protected $casts = [
        'roles' => 'array',
    ];

    /**
     * La ruta física dentro del disco del server no le sirve a ninguna pantalla
     * y describe cómo está guardado el archivo; la descarga siempre va por id.
     */
    protected $hidden = ['ruta'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}


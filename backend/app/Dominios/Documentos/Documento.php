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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

